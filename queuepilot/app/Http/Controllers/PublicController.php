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
    public function landing(): void
    {
        view('landing', ['title' => 'Live patient queues for clinics'], false);
    }

    public function pricing(): void
    {
        redirect('/#pricing');
    }

    public function features(): void
    {
        redirect('/#surfaces');
    }

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

        view('track/show', [
            'title' => 'Token ' . $snapshot['token'],
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

    public function registerForm(): void
    {
        if (Auth::check()) {
            redirect(Auth::isSuper() ? '/admin' : '/app');
            return;
        }
        $plans = (new ClinicService())->plans();
        $selected = Request::string('plan', 'professional');
        view('auth/register', [
            'title' => 'Create your clinic',
            'plans' => $plans,
            'selected' => $selected,
            'old' => [],
        ], false);
    }

    public function register(): void
    {
        $data = [
            'name' => Request::string('name'),
            'owner_name' => Request::string('owner_name'),
            'email' => mb_strtolower(Request::string('email')),
            'phone' => Request::string('phone'),
            'city' => Request::string('city'),
            'district' => Request::string('district'),
            'password' => (string) Request::input('password', ''),
        ];
        $plan = Request::string('plan', 'starter');

        $errors = [];
        if (mb_strlen($data['name']) < 3) {
            $errors['name'] = 'Enter your clinic name.';
        }
        if (mb_strlen($data['owner_name']) < 3) {
            $errors['owner_name'] = 'Enter your full name.';
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        $min = (int) Env::get('security.password_min', 10);
        if (mb_strlen($data['password']) < $min) {
            $errors['password'] = "Use at least {$min} characters.";
        }
        if (Db::scalar('SELECT id FROM users WHERE email = ?', [$data['email']])) {
            $errors['email'] = 'An account with this email already exists. Try signing in.';
        }

        if ($errors !== []) {
            Session::flash('old', array_diff_key($data, ['password' => 1]));
            Session::flash('errors', $errors);
            flash('error', 'Please fix the highlighted fields.');
            redirect('/register?plan=' . urlencode($plan));
            return;
        }

        try {
            $result = (new ClinicService())->register($data, $plan);

            // Prove the address before the clinic can put a patient in a queue.
            // Sending is best-effort: a mail failure must not lose the account
            // somebody just created, so the token is issued anyway and the
            // banner on the billing page offers a resend.
            try {
                $ownerId = (int) Db::scalar('SELECT id FROM users WHERE email = ?', [$data['email']]);
                if ($ownerId > 0) {
                    (new \App\Services\EmailVerificationService())->send($ownerId, true);
                }
            } catch (\Throwable $e) {
                \App\Core\Log::warn('auth.verification_send_failed', ['error' => $e->getMessage()]);
            }

            [$ok] = Auth::attempt($data['email'], $data['password'], Request::ip());
            if (!$ok) {
                flash('success', 'Clinic created. Please sign in.');
                redirect('/login');
                return;
            }
            // Straight to the checklist, not the board. A new owner looking at
            // an empty dashboard has to guess what to do first; the checklist
            // names it and is measured from the data, so it cannot claim more
            // progress than the clinic has.
            flash('success', 'Welcome to QueuePilot! Your 14-day trial has started. These are the steps that make the clinic run — most take a minute.');
            redirect('/getting-started');
        } catch (\Throwable $e) {
            Log::error('clinic.register.failed', ['error' => $e->getMessage()]);
            Session::flash('old', array_diff_key($data, ['password' => 1]));
            flash('error', 'We could not create the clinic: ' . $e->getMessage());
            redirect('/register');
        }
    }

    /** Public demo board so a visitor can see a real live queue. */
    /**
     * Public booking form — only when the clinic has opted in.
     *
     * A clinic that does not use online booking gets a 404, not an empty form.
     * The clinic is addressed by its slug so a patient can be given a plain
     * link; nothing about the clinic's plan or billing is exposed here.
     */
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
        // months ahead by bookings nobody remembers making.
        if (strtotime($date) > strtotime('+' . (new \App\Services\SlotService())->horizonDays($clinicId) . ' days')) {
            $fail('Appointments can only be booked ' . (new \App\Services\SlotService())->horizonDays($clinicId) . ' days ahead.');
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
            (new \App\Services\AppointmentService())->create(
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

        (new \App\Services\AuditService())->log($clinicId, 'appointment.booked_online', 'appointment', null, [
            'date' => $date, 'time' => $time,
        ]);

        view('book/confirmed', [
            'title' => 'Appointment requested · ' . $clinic['name'],
            'clinicRecord' => $clinic,
            'name' => $name,
            'date' => $date,
            'time' => $time,
            'doctor' => (string) Db::scalar('SELECT name FROM doctors WHERE id = ?', [$doctorId]),
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
