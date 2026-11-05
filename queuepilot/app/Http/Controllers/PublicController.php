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
            [$ok] = Auth::attempt($data['email'], $data['password'], Request::ip());
            if (!$ok) {
                flash('success', 'Clinic created. Please sign in.');
                redirect('/login');
                return;
            }
            flash('success', 'Welcome to QueuePilot! Your 14-day trial has started — let\'s open your first queue.');
            redirect('/app');
        } catch (\Throwable $e) {
            Log::error('clinic.register.failed', ['error' => $e->getMessage()]);
            Session::flash('old', array_diff_key($data, ['password' => 1]));
            flash('error', 'We could not create the clinic: ' . $e->getMessage());
            redirect('/register');
        }
    }

    /** Public demo board so a visitor can see a real live queue. */
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
