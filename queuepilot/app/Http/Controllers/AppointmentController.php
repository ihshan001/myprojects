<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Domain\Queue\QueueRepository;
use App\Domain\Queue\QueueService;
use App\Services\AppointmentService;
use App\Services\AuditService;
use App\Services\DoctorService;
use App\Services\PatientService;

final class AppointmentController
{
    private function clinicId(): int
    {
        $id = Auth::clinicId();
        if ($id === null) {
            abort(403, 'Not attached to a clinic.');
        }
        return $id;
    }

    public function index(): void
    {
        $clinicId = $this->clinicId();
        $date = Request::string('date', Db::today());
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = Db::today();
        }
        $doctorId = Request::int('doctor') ?: null;

        view('appointments/index', [
            'title' => 'Appointments',
            'date' => $date,
            'doctorId' => $doctorId,
            'doctors' => (new DoctorService())->all($clinicId),
            'appointments' => (new AppointmentService())->forDay($clinicId, $doctorId, $date),
            'queues' => (new QueueRepository())->liveQueues($clinicId),
        ]);
    }

    public function store(): void
    {
        $clinicId = $this->clinicId();
        $patientId = Request::int('patient_id');
        $doctorId = Request::int('doctor_id');
        $date = Request::string('date', Db::today());
        $time = Request::string('time', '09:00');

        $patients = new PatientService();
        if ($patientId === 0) {
            $name = Request::string('full_name');
            if (mb_strlen($name) < 2) {
                flash('error', 'Enter the patient name.');
                redirect('/appointments');
                return;
            }
            $patient = $patients->findOrCreate($clinicId, [
                'full_name' => $name,
                'phone' => Request::string('phone'),
            ]);
            $patientId = (int) $patient['id'];
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            flash('error', 'Enter a valid date and time.');
            redirect('/appointments');
            return;
        }

        $doctor = (new DoctorService())->find($clinicId, $doctorId);
        if (!$doctor) {
            flash('error', 'Choose a doctor at this clinic.');
            redirect('/appointments');
            return;
        }

        $when = $date . ' ' . $time . ':00';
        try {
            $id = (new AppointmentService())->create($clinicId, $doctorId, $patientId, $when, 'phone');
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/appointments?date=' . urlencode($date));
            return;
        }

        (new AuditService())->log($clinicId, 'appointment.created', 'appointment', $id, [
            'doctor' => $doctor['name'], 'when' => $when,
        ]);
        flash('success', 'Appointment booked for ' . date('D j M, g:i A', strtotime($when)) . '.');
        redirect('/appointments?date=' . urlencode($date));
    }

    /**
     * The doctor cannot come in: cancel their whole day in one action.
     *
     * Why this is not "cancel twenty appointments one at a time":
     *
     *   * A doctor phoning at 07:00 will not tap twenty times, so the desk
     *     ends up cancelling a day by not telling anybody — the worst outcome,
     *     because those patients travel.
     *   * The response **names every patient and their number**, so the person
     *     who does this has a call list. The software can free the slots; it
     *     cannot apologise, and pretending otherwise is how clinics lose trust.
     *   * Nothing is deleted. Rows keep their time and status so the day's
     *     history still explains itself afterwards.
     *
     * A doctor may only cancel **their own** day. `users.doctor_id` is the
     * link; a doctor with no doctor record cannot use this at all, which is
     * the correct failure — the alternative is guessing.
     */
    public function cancelDay(): void
    {
        $clinicId = $this->clinicId();
        $date = Request::string('date', Db::today());
        $doctorId = Request::int('doctor_id');
        $reason = trim(Request::string('reason'));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            flash('error', 'Choose the day to cancel.');
            redirect('/appointments');
            return;
        }

        if (Auth::role() === Auth::ROLE_DOCTOR) {
            $own = (int) Db::scalar('SELECT doctor_id FROM users WHERE id = ?', [(int) Auth::id()]);
            if ($own === 0) {
                abort(403, 'Your account is not linked to a doctor record.');
                return;
            }
            if ($doctorId !== 0 && $doctorId !== $own) {
                abort(403, 'You can only cancel your own list.');
                return;
            }
            $doctorId = $own;
        }

        $doctor = (new DoctorService())->find($clinicId, $doctorId);
        if (!$doctor) {
            flash('error', 'Choose a doctor at this clinic.');
            redirect('/appointments?date=' . urlencode($date));
            return;
        }

        // Deliberately refuses a day that has already gone: cancelling the past
        // rewrites a record that has already been read, and the patients have
        // either been seen or not. Nothing good comes of it.
        if (strtotime($date) < strtotime(Db::today())) {
            flash('error', 'That day has already passed. Cancel a future day instead.');
            redirect('/appointments?date=' . urlencode($date));
            return;
        }

        // The service writes the audit entry itself, with the count as it
        // actually found it — logging again here produced two rows per action,
        // one of them a copy that could disagree.
        $result = (new AppointmentService())->cancelDay(
            $clinicId,
            $doctorId,
            $date,
            $reason === '' ? null : $reason,
            (int) Auth::id()
        );

        if ((int) $result['cancelled'] === 0) {
            flash('warn', 'Nothing was booked for ' . $doctor['name'] . ' on ' . $date . ', so nothing changed.');
            redirect('/appointments?date=' . urlencode($date) . '&doctor=' . $doctorId);
            return;
        }

        // The call list travels with the confirmation, because the next thing
        // the person needs is the phone numbers — not a success notice.
        \App\Core\Session::flash('cancel_day_result', [
            'doctor' => (string) $doctor['name'],
            'date' => $date,
            'count' => (int) $result['cancelled'],
            'patients' => $result['patients'],
        ]);
        flash('success', (string) $result['cancelled'] . ' appointment(s) cancelled for ' . $doctor['name'] . ' on '
            . date('D j M', strtotime($date)) . '.');
        redirect('/appointments?date=' . urlencode($date) . '&doctor=' . $doctorId);
    }

    /** Check in an appointment: issues a token and links it to the booking. */
    public function checkin(string $id): void
    {
        $clinicId = $this->clinicId();
        $service = new AppointmentService();
        $appointment = $service->find($clinicId, (int) $id);
        if (!$appointment) {
            abort(404, 'Appointment not found.');
            return;
        }
        if ($appointment['status'] !== 'booked') {
            flash('warn', 'That appointment is already ' . str_replace('_', ' ', $appointment['status']) . '.');
            redirect('/appointments');
            return;
        }

        $queue = (new QueueRepository())->queueForDoctor($clinicId, (int) $appointment['doctor_id']);
        if (!$queue) {
            flash('error', 'No queue is open for that doctor yet. Open the session first.');
            redirect('/appointments');
            return;
        }

        // Apply the clinic's late-arrival policy before ordering the patient.
        $policy = $service->arrivalPolicy($appointment, Auth::clinic());

        try {
            $result = (new QueueService())->issueToken($clinicId, (int) $queue['id'], (int) $appointment['patient_id'], [
                'source' => 'appointment',
                'appointment_id' => (int) $appointment['id'],
                'appointment_weight' => $policy['weight'],
                'walkin_weight' => 3,
                'note' => $policy['message'],
            ]);
            $service->markCheckedIn($clinicId, (int) $appointment['id']);
            flash('success', 'Checked in — token ' . $result['token'] . ' issued to ' . $appointment['full_name'] . '. ' . $policy['message']);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/appointments?date=' . urlencode(substr((string) $appointment['scheduled_for'], 0, 10)));
    }
}
