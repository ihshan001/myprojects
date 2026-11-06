<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Db;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Session;
use App\Services\KioskService;

/**
 * The waiting-room kiosk.
 *
 * A tablet on a stand, unlocked, in a room with strangers in it. Everything
 * here is shaped by that: no login, one credential, no lists, no names, and a
 * receipt that expires so the next person does not read the last person's
 * visit.
 *
 * The one thing it must not do is issue the same token twice. A patient who
 * taps "Get my number", sees the receipt and presses back-and-refresh must not
 * end up with two places in the queue — so POST redirects to a GET receipt
 * held in the session, exactly as the reception desk does.
 */
final class KioskController
{
    /** How long a receipt stays on screen before the kiosk resets itself. */
    private const RECEIPT_SECONDS = 45;

    private function clinicFor(string $key): ?array
    {
        $clinicId = (new KioskService())->clinicForKey($key);
        if ($clinicId === null) {
            return null;
        }
        return Db::one('SELECT id, name, brand_color, locale, phone FROM clinics WHERE id = ? AND is_active = 1', [$clinicId]);
    }

    /** `GET /kiosk/{key}` — the kiosk's single screen. */
    public function show(string $key): void
    {
        // A kiosk is a public terminal: anyone in the room can type. The limit
        // is generous for a queue of thirty people and useless for a script.
        RateLimiter::enforce('kiosk:key:' . $key, 120, 60);
        RateLimiter::enforce('kiosk:ip:' . Request::ip(), 240, 60);

        $clinic = $this->clinicFor($key);
        if ($clinic === null) {
            http_response_code(404);
            view('kiosk/invalid', ['title' => 'Kiosk not set up'], false, false);
            return;
        }

        $receipt = Session::takeFlash('kiosk_receipt');
        view('kiosk/show', [
            'title' => (string) $clinic['name'] . ' · self check-in',
            'clinic' => $clinic,
            'key' => $key,
            'queues' => (new KioskService())->openQueues((int) $clinic['id']),
            'receipt' => is_array($receipt) ? $receipt : null,
            'receiptSeconds' => self::RECEIPT_SECONDS,
            'error' => Session::takeFlash('kiosk_error'),
            'old' => Session::takeFlash('kiosk_old') ?? [],
        ], false, false);
    }

    /** `POST /kiosk/{key}` — issue one walk-in token. */
    public function issue(string $key): void
    {
        RateLimiter::enforce('kiosk:key:' . $key, 120, 60);
        RateLimiter::enforce('kiosk:ip:' . Request::ip(), 240, 60);

        $clinic = $this->clinicFor($key);
        if ($clinic === null) {
            http_response_code(404);
            view('kiosk/invalid', ['title' => 'Kiosk not set up'], false, false);
            return;
        }

        // CSRF still applies, enforced by the router (the route is declared
        // without the `csrf => false` opt-out that the tracking link needs).
        // The kiosk is unauthenticated, not unprotected: a page on the same
        // network cannot use the lobby tablet to mint tokens behind the
        // patient's back.

        $name = trim(Request::string('full_name'));
        $phone = preg_replace('/\s+/', '', Request::string('phone')) ?? '';
        $queueId = Request::int('queue_id');

        $fail = function (string $message) use ($key, $name, $phone, $queueId): void {
            Session::flash('kiosk_error', $message);
            Session::flash('kiosk_old', ['full_name' => $name, 'phone' => $phone, 'queue_id' => $queueId]);
            redirect('/kiosk/' . $key);
        };

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $fail('Please type your name.');
            return;
        }
        // Same rule as the booking page: a Sri Lankan mobile, or nothing. A
        // half-typed number is worse than no number, because the clinic will
        // try to call it.
        if ($phone !== '' && preg_match('/^0[0-9]{9}$/', $phone) !== 1) {
            $fail('That phone number does not look right. Type 0771234567, or leave it empty.');
            return;
        }

        $result = (new KioskService())->issue((int) $clinic['id'], $queueId, $name, $phone);
        if (!($result['ok'] ?? false)) {
            $fail((string) $result['message']);
            return;
        }

        Session::flash('kiosk_receipt', $result + ['at' => time()]);
        redirect('/kiosk/' . $key);
    }
}
