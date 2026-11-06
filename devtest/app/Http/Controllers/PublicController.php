<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Env;
use App\Core\Log;
use App\Core\Request;
use App\Core\Session;
use App\Domain\Queue\QueueRepository;
use App\Domain\Queue\QueueService;
use App\Services\ClinicService;
use App\Support\QrCode;

final class PublicController
{
    /* DEVTEST: landing, pricing, features and self-service registration are
       removed. What remains here is patient-facing and needs no account:
       tracking, booking, and the signed appointment-management links. */




    /** Patient tracking page: the whole point of "no app to install". */
    public function track(string $token): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token)) {
            http_response_code(404);
            view('track/invalid', ['title' => 'Link not valid'], false);
            return;
        }

        $entry = (new QueueRepository())->entryByToken($token);
        $expired = $entry && $entry['track_expires_at'] !== null
            && strtotime((string) $entry['track_expires_at']) < time();

        if (!$entry || $expired) {
            http_response_code($entry ? 410 : 404);
            view('track/invalid', [
                'title' => $entry ? 'Link expired' : 'Link not valid',
                'expired' => (bool) $entry,
                'clinicPhone' => $entry['clinic_phone'] ?? null,
            ], false);
            return;
        }

        $service = new QueueService();
        $snapshot = $service->publicSnapshot($entry);
        $snapshot['status_message'] = match ((string) $entry['status']) {
            'waiting' => 'Please stay close to the waiting area — we will call your token.',
            'called' => 'It is your turn. Please go to ' . ($entry['room_name'] ?? 'the consultation room') . ' now.',
            'in_consultation' => 'You are with the doctor now.',
            'completed' => 'Your visit is complete. Thank you — get well soon.',
            'skipped' => 'You were marked absent. Please speak to reception to rejoin the queue.',
            'no_show' => 'You were marked as not present.',
            'cancelled' => 'This visit was cancelled. Contact the clinic if this is unexpected.',
            'transferred' => 'You have been transferred to another service at this clinic.',
            default => '',
        };

        // Full link for the "share/print" affordance, never exposes clinic ids.
        $fullLink = rtrim((string) Env::get('app.url', ''), '/') . '/t/' . $token;

        // A visit that came from a booking carries the appointment, so the
        // patient can move it from the same link they use to watch the queue.
        // Only while the visit is still `waiting`: once the doctor has called
        // the token, changing the booking underneath the consultation would
        // contradict the board.
        $appointment = null;
        if (!empty($entry['appointment_id']) && (string) $entry['status'] === 'waiting') {
            $appointment = Db::one(
                "SELECT id, scheduled_for, status FROM appointments
                  WHERE id = ? AND clinic_id = ? AND status IN ('booked','checked_in')",
                [(int) $entry['appointment_id'], (int) $entry['clinic_id']]
            );
        }

        // The satisfaction question, asked here because this is the one place
        // the patient is already looking after the visit ends.
        $feedback = (new \App\Services\FeedbackService())->statusFor($entry);

        view('track/show', [
            'title' => 'Token ' . $snapshot['token'],
            'feedback' => $feedback,
            'appointment' => $appointment,
            'rescheduleDate' => \App\Core\Request::string('date', substr((string) ($appointment['scheduled_for'] ?? Db::today()), 0, 10)),
            'rescheduleSlots' => $appointment !== null
                ? (new \App\Services\SlotService())->slotsFor(
                    (int) $entry['clinic_id'],
                    (int) $entry['doctor_id'],
                    \App\Core\Request::string('date', substr((string) $appointment['scheduled_for'], 0, 10))
                )
                : ['slots' => [], 'reason' => null],
            'snapshot' => $snapshot,
            'token' => $token,
            'qr' => QrCode::dataUri($fullLink, 4, '#0f172a', '#ffffff'),
            'fullLink' => $fullLink,
            'clinic' => [
                'name' => $entry['clinic_name'],
                'phone' => $entry['clinic_phone'],
                'brand_color' => $entry['brand_color'],
            ],
        ], false);
    }

    /**
     * A patient cancels their own visit from the tracking link.
     *
     * Authorisation is the tracking token itself: 128 bits of randomness,
     * delivered only to that patient. There is no login because the person
     * holding the phone is the patient.
     *
     * Deliberate limits, because this endpoint is reachable by anyone with a
     * link:
     *   * Only `waiting` entries can be cancelled. Once the doctor has called
     *     the token, walking away is a no-show and a receptionist decision —
     *     letting a link cancel a consultation in progress would erase work.
     *   * The clinic is derived from the entry, never from input, so the
     *     token cannot be pointed at another clinic's queue.
     *   * A reason of 'Cancelled by patient' is written to the audit log, so
     *     reception can tell a self-cancel from a staff cancel.
     */
    public function cancelTracked(string $token): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token)) {
            http_response_code(404);
            view('track/invalid', ['title' => 'Link not valid'], false);
            return;
        }

        $repo = new QueueRepository();
        $entry = $repo->entryByToken($token);

        $expired = $entry && $entry['track_expires_at'] !== null
            && strtotime((string) $entry['track_expires_at']) < time();

        if (!$entry || $expired) {
            http_response_code($entry ? 410 : 404);
            view('track/invalid', [
                'title' => $entry ? 'Link expired' : 'Link not valid',
                'expired' => (bool) $entry,
                'clinicPhone' => $entry['clinic_phone'] ?? null,
            ], false);
            return;
        }

        // Rate limited per token: a leaked link should not become a way to
        // hammer the queue engine.
        $limit = \App\Core\RateLimiter::hit('track-cancel:' . $token, 5, 3600);
        if (!$limit['allowed']) {
            flash('error', 'Too many attempts from this link. Please call the clinic.');
            redirect('/t/' . $token);
            return;
        }

        if ((string) $entry['status'] !== 'waiting') {
            // Not an error page — the patient is told plainly why, and what to
            // do instead. The status may have changed a second ago.
            Session::flash('cancel_refused', (string) $entry['status']);
            redirect('/t/' . $token);
            return;
        }

        $result = (new QueueService())->apply((int) $entry['clinic_id'], (int) $entry['id'], 'cancel', [
            'reason' => 'Cancelled by patient from the tracking link',
        ]);

        if ($result['ok']) {
            Session::flash('cancel_ok', true);
        } else {
            flash('error', $result['message']);
        }

        redirect('/t/' . $token);
    }

    /**
     * A patient moves their own appointment from the tracking link.
     *
     * Same authorisation as cancelling: the 128-bit token, held only by that
     * patient. Same limits, and one more:
     *
     *   * Only a `waiting` visit with a live appointment can be moved. Once
     *     the doctor has called the token, the patient is in the room, not
     *     rebooking.
     *   * The **doctor is taken from the entry**, never from the form. A
     *     patient can pick a time, not a different doctor — moving to another
     *     clinician is a reception conversation, not a link.
     *   * The slot must be one the clinic is actually offering; `isOffered`
     *     is asked rather than trusting the posted string, so a crafted POST
     *     cannot put an appointment at 03:00 on a closed day.
     *
     * A `late` arrival is not moved automatically: it is still a booked
     * appointment and arriving late does not forfeit the booking.
     */
    public function rescheduleTracked(string $token): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token)) {
            http_response_code(404);
            view('track/invalid', ['title' => 'Link not valid'], false);
            return;
        }

        $entry = (new QueueRepository())->entryByToken($token);
        $expired = $entry && $entry['track_expires_at'] !== null
            && strtotime((string) $entry['track_expires_at']) < time();
        if (!$entry || $expired) {
            http_response_code($entry ? 410 : 404);
            view('track/invalid', [
                'title' => $entry ? 'Link expired' : 'Link not valid',
                'expired' => (bool) $entry,
                'clinicPhone' => $entry['clinic_phone'] ?? null,
            ], false);
            return;
        }

        // A link is public. Five moves an hour is enough for anybody honest and
        // not enough to grind through a clinic's whole day.
        $limit = \App\Core\RateLimiter::hit('track-reschedule:' . $token, 5, 3600);
        if (!$limit['allowed']) {
            flash('error', 'Too many changes from this link. Please call the clinic.');
            redirect('/t/' . $token);
            return;
        }

        if ((string) $entry['status'] !== 'waiting') {
            Session::flash('reschedule_refused', (string) $entry['status']);
            redirect('/t/' . $token);
            return;
        }
        if (empty($entry['appointment_id'])) {
            Session::flash('reschedule_refused', 'no_appointment');
            redirect('/t/' . $token);
            return;
        }

        $date = Request::string('date');
        $time = Request::string('time');
        $clinicId = (int) $entry['clinic_id'];
        $doctorId = (int) $entry['doctor_id'];

        $slots = new \App\Services\SlotService();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
            || preg_match('/^\d{2}:\d{2}$/', $time) !== 1
            || !$slots->isOffered($clinicId, $doctorId, $date, $time)) {
            flash('error', 'That time is not one of the clinic’s slots. Pick one from the list.');
            redirect('/t/' . $token . '?date=' . urlencode($date));
            return;
        }

        try {
            $result = (new \App\Services\AppointmentService())->reschedule(
                $clinicId,
                (int) $entry['appointment_id'],
                $date . ' ' . $time . ':00',
                'Moved by patient from the tracking link'
            );
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/t/' . $token . '?date=' . urlencode($date));
            return;
        }

        Db::insert(
            'INSERT INTO audit_logs (clinic_id, actor_id, action, entity, entity_id, meta_json, created_at)
             VALUES (?,?,?,?,?,?,?)',
            [$clinicId, null, 'appointment.rescheduled_by_patient', 'appointment', (int) $entry['appointment_id'],
             json_encode(['from' => $result['from'], 'to' => $result['to'], 'entry_id' => (int) $entry['id']]), Db::now()]
        );

        Session::flash('reschedule_ok', $date . ' ' . $time);
        redirect('/t/' . $token);
    }

    /**
     * A patient rates the visit they just had, from their own link.
     *
     * Authorisation is the same 128-bit token that has been showing them the
     * wait — no new credential, and nothing that identifies them beyond the
     * visit. Three refusals are deliberate:
     *
     *   * Only a **completed** visit can be rated. A cancelled or no-show visit
     *     has no consultation to judge.
     *   * **One answer per visit**, enforced by a unique index rather than a
     *     check, so a double-tap cannot produce two ratings.
     *   * Anything **older than a fortnight** is declined: by then the answer
     *     is about the memory of the survey, not the visit, and mixing the two
     *     makes every average meaningless.
     *
     * The comment is optional. Requiring prose loses exactly the people with
     * the least time — usually the ones who waited longest.
     */
    public function rateVisit(string $token): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token)) {
            http_response_code(404);
            view('track/invalid', ['title' => 'Link not valid'], false);
            return;
        }

        $entry = (new QueueRepository())->entryByToken($token);
        $expired = $entry && $entry['track_expires_at'] !== null
            && strtotime((string) $entry['track_expires_at']) < time();
        if (!$entry || $expired) {
            http_response_code($entry ? 410 : 404);
            view('track/invalid', [
                'title' => $entry ? 'Link expired' : 'Link not valid',
                'expired' => (bool) $entry,
                'clinicPhone' => $entry['clinic_phone'] ?? null,
            ], false);
            return;
        }

        // A rating is cheap to submit and valuable to a clinic, which makes it
        // worth grinding. Ten an hour per link is more than any patient needs.
        $limit = \App\Core\RateLimiter::hit('track-rate:' . $token, 10, 3600);
        if (!$limit['allowed']) {
            flash('error', 'Too many tries from this link. Thank you anyway.');
            redirect('/t/' . $token);
            return;
        }

        $rating = Request::int('rating');
        $waitRating = Request::int('wait_rating');
        $comment = Request::string('comment');

        try {
            (new \App\Services\FeedbackService())->record(
                (int) $entry['clinic_id'],
                (int) $entry['id'],
                $rating,
                $waitRating > 0 ? $waitRating : null,
                $comment === '' ? null : $comment
            );
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/t/' . $token);
            return;
        }

        Session::flash('rated_ok', $rating);
        redirect('/t/' . $token);
    }



    /** Public demo board so a visitor can see a real live queue. */
    /**
     * Public booking form — only when the clinic has opted in.
     *
     * A clinic that does not use online booking gets a 404, not an empty form.
     * The clinic is addressed by its slug so a patient can be given a plain
     * link; nothing about the clinic's plan or billing is exposed here.
     */
    /**
     * `GET /a/{id}.{signature}` — the page a booked patient opens from their
     * confirmation or reminder message.
     *
     * Everything is derived from the database row, never from the URL beyond
     * the id; the signature only proves the holder was given the link. A
     * patient therefore cannot enumerate `/a/1`, `/a/2` and read who is
     * seeing which doctor.
     */
    public function manageAppointment(string $token): void
    {
        $post = $this->loadManagedAppointment($token);
        if ($post === null) {
            return; // loadManagedAppointment already rendered the 404/410
        }
        [$appointment, $clinic] = $post;

        $slots = new \App\Services\SlotService();
        $date = Request::string('date', substr((string) $appointment['scheduled_for'], 0, 10));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $date = substr((string) $appointment['scheduled_for'], 0, 10);
        }
        $availability = $slots->slotsFor((int) $appointment['clinic_id'], (int) $appointment['doctor_id'], $date);

        view('book/manage', [
            'title' => 'Your appointment · ' . $clinic['name'],
            'clinicRecord' => $clinic,
            'appointment' => $appointment,
            'token' => $token,
            'date' => $date,
            'slots' => $availability['slots'],
            'slotReason' => $availability['reason'],
            'horizon' => $slots->horizonDays((int) $appointment['clinic_id']),
            'moved' => Session::takeFlash('manage_moved'),
            'cancelled' => Session::takeFlash('manage_cancelled'),
            'error' => Session::takeFlash('manage_error'),
        ], false);
    }

    /** `POST /a/{id}.{signature}/reschedule` */
    public function rescheduleManaged(string $token): void
    {
        $post = $this->loadManagedAppointment($token);
        if ($post === null) {
            return;
        }
        [$appointment, $clinic] = $post;
        $appointmentId = (int) $appointment['id'];

        // Rate limited per booking, not per IP: a family on one phone shares
        // an address, and a shared clinic tablet shares one with everybody.
        $limit = \App\Core\RateLimiter::hit('manage-reschedule:' . $appointmentId, 5, 3600);
        if (!$limit['allowed']) {
            Session::flash('manage_error', 'Too many changes. Please call the clinic.');
            redirect('/a/' . $token);
            return;
        }

        $date = Request::string('date');
        $time = Request::string('time');
        $slots = new \App\Services\SlotService();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
            || preg_match('/^\d{2}:\d{2}$/', $time) !== 1
            || !$slots->isOffered((int) $appointment['clinic_id'], (int) $appointment['doctor_id'], $date, $time)) {
            Session::flash('manage_error', 'That time is not one of the clinic’s slots. Pick one from the list.');
            redirect('/a/' . $token . '?date=' . urlencode($date));
            return;
        }

        try {
            $result = (new \App\Services\AppointmentService())->reschedule(
                (int) $appointment['clinic_id'],
                $appointmentId,
                $date . ' ' . $time . ':00',
                'Moved by the patient from their booking link'
            );
        } catch (\RuntimeException $e) {
            Session::flash('manage_error', $e->getMessage());
            redirect('/a/' . $token . '?date=' . urlencode($date));
            return;
        }

        (new \App\Services\AuditService())->log((int) $appointment['clinic_id'], 'appointment.rescheduled_by_patient', 'appointment', $appointmentId, [
            'from' => $result['from'], 'to' => $result['to'], 'via' => 'booking_link',
        ]);

        // The signature covers the old time, so the page they are redirected
        // to is the one the new time signs — otherwise a successful move would
        // land the patient on "this link is no longer valid".
        $fresh = Db::one('SELECT scheduled_for FROM appointments WHERE id = ?', [$appointmentId]);
        $nextToken = $appointmentId . '.' . \App\Services\AppointmentService::manageSignature($appointmentId, (string) $fresh['scheduled_for']);
        Session::flash('manage_moved', $date . ' ' . $time);
        redirect('/a/' . $nextToken);
    }

    /** `POST /a/{id}.{signature}/cancel` */
    public function cancelManaged(string $token): void
    {
        $post = $this->loadManagedAppointment($token);
        if ($post === null) {
            return;
        }
        [$appointment, $clinic] = $post;
        $appointmentId = (int) $appointment['id'];

        $limit = \App\Core\RateLimiter::hit('manage-cancel:' . $appointmentId, 5, 3600);
        if (!$limit['allowed']) {
            Session::flash('manage_error', 'Too many changes. Please call the clinic.');
            redirect('/a/' . $token);
            return;
        }

        $service = new \App\Services\AppointmentService();
        try {
            $service->cancel((int) $appointment['clinic_id'], $appointmentId, 'Cancelled by the patient from their booking link');
        } catch (\RuntimeException $e) {
            Session::flash('manage_error', $e->getMessage());
            redirect('/a/' . $token);
            return;
        }

        (new \App\Services\AuditService())->log((int) $appointment['clinic_id'], 'appointment.cancelled_by_patient', 'appointment', $appointmentId, [
            'via' => 'booking_link',
        ]);
        Session::flash('manage_cancelled', true);
        redirect('/a/' . $token);
    }

    /**
     * Resolve `{id}.{signature}` to a live appointment, or render the refusal.
     *
     * Returns null after rendering, so callers only have to `return`.
     *
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}|null
     */
    private function loadManagedAppointment(string $token): ?array
    {
        if (preg_match('/^(\d{1,12})\.([a-f0-9]{32})$/', $token, $m) !== 1) {
            http_response_code(404);
            view('track/invalid', ['title' => 'Link not valid'], false);
            return null;
        }

        $appointment = Db::one(
            'SELECT a.*, d.name AS doctor_name, d.id AS doctor_id, p.full_name
               FROM appointments a
               JOIN doctors d ON d.id = a.doctor_id
               JOIN patients p ON p.id = a.patient_id
              WHERE a.id = ?',
            [(int) $m[1]]
        );
        $clinic = $appointment !== null
            ? Db::one('SELECT * FROM clinics WHERE id = ? AND is_active = 1', [(int) $appointment['clinic_id']])
            : null;

        $valid = $appointment !== null && $clinic !== null
            && \App\Services\AppointmentService::manageTokenValid((int) $appointment['id'], (string) $appointment['scheduled_for'], $m[2]);

        if (!$valid) {
            http_response_code(404);
            view('track/invalid', ['title' => 'Link not valid'], false);
            return null;
        }

        // A used or moved link is not an error the patient caused; it means
        // the booking moved on. Say where things stand rather than "invalid".
        if (!in_array((string) $appointment['status'], ['booked', 'checked_in'], true)) {
            http_response_code(410);
            view('book/manage-gone', [
                'title' => 'This booking has changed',
                'clinicRecord' => $clinic,
                'appointment' => $appointment,
            ], false);
            return null;
        }

        return [$appointment, $clinic];
    }

    public function bookForm(string $slug): void
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE slug = ? AND is_active = 1', [$slug]);
        if (!$clinic || (int) ($clinic['allow_online_booking'] ?? 0) !== 1) {
            abort(404, 'This clinic does not take bookings online.');
            return;
        }

        $date = Request::string('date', date('Y-m-d', strtotime('+1 day')));
        // A doctor on leave is shown but not selectable, with the reason
        // visible. Hiding the row entirely would make a patient think the
        // doctor no longer works here.
        $doctorService = new \App\Services\DoctorService();
        $doctors = $doctorService->bookable((int) $clinic['id'], $date);

        // Slots belong to a doctor and a date, not to the clinic. Resolve the
        // chosen doctor (defaulting to the first bookable one) and ask the
        // slot service what is genuinely available. The template renders the
        // reason when the list is empty instead of an empty dropdown.
        $slotService = new \App\Services\SlotService();
        $doctorId = Request::int('doctor_id');
        $chosen = null;
        foreach ($doctors as $d) {
            if ((int) $d['id'] === $doctorId) {
                $chosen = $d;
                break;
            }
        }
        if ($chosen === null && $doctors !== []) {
            $chosen = $doctors[0];
        }
        $availability = $chosen !== null
            ? $slotService->slotsFor((int) $clinic['id'], (int) $chosen['id'], $date)
            : ['slots' => [], 'reason' => 'No doctor is available on this date.', 'hours' => null];

        view('book/form', [
            'title' => 'Book an appointment · ' . $clinic['name'],
            'clinicRecord' => $clinic,
            'doctors' => $doctors,
            'date' => $date,
            'slots' => $availability['slots'],
            'slotReason' => $availability['reason'],
            'hours' => $availability['hours'],
            'chosenDoctorId' => $chosen !== null ? (int) $chosen['id'] : null,
            'horizon' => $slotService->horizonDays((int) $clinic['id']),
            'error' => null,
        ], false);
    }

    public function book(string $slug): void
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE slug = ? AND is_active = 1', [$slug]);
        if (!$clinic || (int) ($clinic['allow_online_booking'] ?? 0) !== 1) {
            abort(404, 'This clinic does not take bookings online.');
            return;
        }
        $clinicId = (int) $clinic['id'];

        // Booking is unauthenticated by design, so the only defence against a
        // script filling a doctor's day is a rate limit. Per IP, generous
        // enough for a family booking three appointments, tight enough that
        // one host cannot create hundreds.
        \App\Core\RateLimiter::enforce(
            'booking:ip:' . Request::ip(),
            (int) \App\Core\Env::get('security.booking_per_min', 8),
            60
        );

        $doctorId = Request::int('doctor_id');
        $name = trim(Request::string('full_name'));
        $phone = trim(Request::string('phone'));
        $date = Request::string('date');
        $time = Request::string('time', '09:00');

        $fail = function (string $message, int $doctorId = 0) use ($clinic, $slug, $date): void {
            $doctors = (new \App\Services\DoctorService())->bookable((int) $clinic['id'], $date);
            $slotService = new \App\Services\SlotService();
            $chosen = null;
            foreach ($doctors as $d) {
                if ((int) $d['id'] === $doctorId) {
                    $chosen = $d;
                    break;
                }
            }
            if ($chosen === null && $doctors !== []) {
                $chosen = $doctors[0];
            }
            $availability = $chosen !== null
                ? $slotService->slotsFor((int) $clinic['id'], (int) $chosen['id'], $date)
                : ['slots' => [], 'reason' => 'No doctor is available on this date.', 'hours' => null];

            http_response_code(422);
            view('book/form', [
                'title' => 'Book an appointment · ' . $clinic['name'],
                'clinicRecord' => $clinic,
                'doctors' => $doctors,
                'date' => $date,
                'slots' => $availability['slots'],
                'slotReason' => $availability['reason'],
                'hours' => $availability['hours'],
                'chosenDoctorId' => $chosen !== null ? (int) $chosen['id'] : null,
                'horizon' => $slotService->horizonDays((int) $clinic['id']),
                'error' => $message,
            ], false);
        };

        if ($doctorId <= 0 || Db::scalar('SELECT id FROM doctors WHERE id = ? AND clinic_id = ? AND is_active = 1', [$doctorId, $clinicId]) === null) {
            $fail('Choose a doctor.');
            return;
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $fail('Enter the patient name.');
            return;
        }
        if ((new \App\Services\DoctorService())->isOnLeave($clinicId, $doctorId, $date)) {
            $fail('That doctor is on leave on ' . $date . '. Choose another day or another doctor.', $doctorId);
            return;
        }
        if (preg_match('/^0[0-9]{9}$/', preg_replace('/\s+/', '', $phone)) !== 1) {
            $fail('Enter a Sri Lankan mobile number, for example 077 123 4567.');
            return;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || strtotime($date) < strtotime(Db::today())) {
            $fail('Choose a date from today onwards.');
            return;
        }
        // The horizon is a rule, not a UI hint: thirty days is enough for a
        // patient and short enough that the clinic's schedule is not frozen
        // months ahead by bookings nobody remembers making. A service can
        // set its own — a physio course is booked six weeks out, a flu
        // clinic wants nothing beyond tomorrow.
        $doctorKind = Db::scalar(
            'SELECT kind FROM queues WHERE clinic_id = ? AND doctor_id = ? ORDER BY id DESC LIMIT 1',
            [$clinicId, $doctorId]
        );
        $policy = (new \App\Services\AppointmentPolicyService())->resolve(
            $clinicId,
            $doctorKind !== null ? (string) $doctorKind : null,
            $doctorId
        );
        if (strtotime($date) > strtotime('+' . $policy['horizon_days'] . ' days')) {
            $fail('Appointments can only be booked ' . $policy['horizon_days'] . ' days ahead for this service.');
            return;
        }
        if (preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
            $fail('Choose a time.');
            return;
        }
        // The dropdown is a suggestion; this is the request of record. Without
        // this check a script (or a stale tab) can post any minute of the day
        // and create an appointment at a time the clinic never offered.
        if (!(new \App\Services\SlotService())->isOffered($clinicId, $doctorId, $date, $time)) {
            $fail('That time is not available. Please pick one of the times shown.', $doctorId);
            return;
        }
        // Leave was already checked above, before the slot list was consulted:
        // a doctor on leave has no slots, and reporting "not available" would
        // hide the real reason.

        // A booking is a request, not a queue position: the patient is checked
        // in when they physically arrive, which is where the late-arrival
        // policy is applied.
        try {
            $patient = (new \App\Services\PatientService())->findOrCreate($clinicId, [
                'full_name' => $name,
                'phone' => preg_replace('/\s+/', '', $phone),
            ]);
            $appointmentId = (new \App\Services\AppointmentService())->create(
                $clinicId,
                $doctorId,
                (int) $patient['id'],
                $date . ' ' . $time . ':00',
                'online'
            );
        } catch (\RuntimeException $e) {
            $fail($e->getMessage());
            return;
        }

        (new \App\Services\AuditService())->log($clinicId, 'appointment.booked_online', 'appointment', $appointmentId, [
            'date' => $date, 'time' => $time,
        ]);

        view('book/confirmed', [
            'title' => 'Appointment requested · ' . $clinic['name'],
            'clinicRecord' => $clinic,
            'name' => $name,
            'date' => $date,
            'time' => $time,
            'doctor' => (string) Db::scalar('SELECT name FROM doctors WHERE id = ?', [$doctorId]),
            // The patient's own link, good until the booking moves. This is the
            // whole point of booking online: no phone call to change a time.
            'manageUrl' => \App\Services\AppointmentService::manageUrl($appointmentId, $date . ' ' . $time . ':00'),
        ], false);
    }

    public function demoBoard(): void
    {
        $link = Db::scalar(
            "SELECT e.track_token FROM queue_entries e
               JOIN clinics c ON c.id = e.clinic_id
              WHERE c.slug = 'serenity' AND e.status IN ('waiting','called','in_consultation')
              ORDER BY CASE e.status WHEN 'called' THEN 0 WHEN 'in_consultation' THEN 1 ELSE 2 END, e.id
              LIMIT 1"
        );
        if (!$link) {
            flash('warn', 'Demo data is not seeded yet.');
            redirect('/');
            return;
        }
        redirect('/t/' . $link);
    }

    public function manifest(): void
    {
        header('Content-Type: application/manifest+json; charset=utf-8');
        $name = (string) (Auth::clinic()['name'] ?? 'QueuePilot');
        echo json_encode([
            'name' => $name . ' · QueuePilot',
            'short_name' => 'QueuePilot',
            'description' => 'Live clinic queues — tokens, waiting times and patient tracking.',
            'start_url' => '/app',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait-primary',
            'background_color' => '#f6f8fb',
            'theme_color' => '#0F766E',
            'categories' => ['medical', 'productivity'],
            'icons' => [
                ['src' => '/assets/img/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => '/assets/img/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function serviceWorker(): void
    {
        header('Content-Type: text/javascript; charset=utf-8');
        header('Cache-Control: no-cache');
        // The shell is cached; API traffic is always network-first so a stale
        // queue can never be mistaken for a live one.
        echo <<<'JS'
const VERSION = 'qp-v1';
const SHELL = ['/assets/build/app.css', '/assets/js/app.js', '/assets/img/icon.svg'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(VERSION).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Never cache live data or state-changing requests.
  if (event.request.method !== 'GET' || url.pathname.startsWith('/api/')) {
    return;
  }

  // Static assets: cache-first.
  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(
      caches.match(event.request).then((hit) => hit || fetch(event.request).then((res) => {
        const copy = res.clone();
        caches.open(VERSION).then((c) => c.put(event.request, copy));
        return res;
      }))
    );
    return;
  }

  // Pages: network-first, fall back to the cached shell with an offline notice.
  event.respondWith(
    fetch(event.request).catch(() =>
      caches.match(event.request).then((hit) => hit || new Response(
        '<!doctype html><meta name="viewport" content="width=device-width">' +
        '<body style="font:16px system-ui;padding:2rem;color:#0f172a">' +
        '<h1 style="font-size:1.1rem">You are offline</h1>' +
        '<p style="color:#64748b">QueuePilot needs a connection to show live queue data. ' +
        'Your last known position is still on screen if you had it open.</p></body>',
        { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503 }
      ))
    )
  );
});
JS;
    }
}
