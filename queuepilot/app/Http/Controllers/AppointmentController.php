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
        $id = (new AppointmentService())->create($clinicId, $doctorId, $patientId, $when, 'phone');

        (new AuditService())->log($clinicId, 'appointment.created', 'appointment', $id, [
            'doctor' => $doctor['name'], 'when' => $when,
        ]);
        flash('success', 'Appointment booked for ' . date('D j M, g:i A', strtotime($when)) . '.');
        redirect('/appointments?date=' . urlencode($date));
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

        try {
            $result = (new QueueService())->issueToken($clinicId, (int) $queue['id'], (int) $appointment['patient_id'], [
                'source' => 'appointment',
                'appointment_id' => (int) $appointment['id'],
                'appointment_weight' => 1,
                'walkin_weight' => 3,
            ]);
            $service->markCheckedIn($clinicId, (int) $appointment['id']);
            flash('success', 'Checked in — token ' . $result['token'] . ' issued to ' . $appointment['full_name'] . '.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/appointments?date=' . urlencode(substr((string) $appointment['scheduled_for'], 0, 10)));
    }
}
