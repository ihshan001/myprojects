<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Core\Session;
use App\Core\Str;
use App\Services\AuditService;
use App\Services\DisplayKeyService;
use App\Services\KioskService;

/**
 * Waiting-room screen.
 *
 * Security posture: a separate, revocable key — not the staff session — so a
 * spare tablet in the lobby can show the board without giving anyone access to
 * patient data. The payload contains tokens and room names only.
 */
final class DisplayController
{
    public function screen(): void
    {
        $clinicId = Auth::clinicId();
        if ($clinicId === null) {
            abort(403, 'Display mode is for clinic accounts.');
        }

        $key = $this->ensureKey($clinicId);
        $clinic = Auth::clinic();

        view('display/screen', [
            'title' => 'Waiting room',
            'key' => $key,
            'clinicRecord' => $clinic,
            'queues' => Db::all(
                'SELECT q.*, d.name AS doctor_name, r.name AS room_name
                   FROM queues q
                   LEFT JOIN doctors d ON d.id = q.doctor_id
                   LEFT JOIN rooms r ON r.id = d.room_id
                  WHERE q.clinic_id = ? AND q.session_date = ?
                  ORDER BY q.id',
                [$clinicId, Db::today()]
            ),
            'canRotate' => Auth::can('clinic.manage'),
            'kioskKey' => (new KioskService())->keyFor($clinicId),
            'kioskNewKey' => Session::takeFlash('kiosk_new_key'),
        ], false);
    }

    /**
     * Public lobby screen, addressed by the revocable display key.
     *
     * Same markup as the staff preview, but no session: a TV browser cannot
     * log in, and it must never see patient names — the board payload is
     * tokens and rooms only. Anything else about the clinic is public anyway.
     */
    public function publicScreen(string $key): void
    {
        // A department display key is its own token, so this screen has to
        // recognise both kinds. Looking only at `display_token` made department
        // links 404 even though they had just been issued.
        //
        // Resolution now goes through the service, which handles per-screen
        // keys and still honours the legacy `settings` values so a screen that
        // has been running for a year does not go blank after a migration.
        $resolved = (new DisplayKeyService())->resolve($key);
        $clinicId = $resolved['clinic_id'] ?? null;

        $departmentName = null;
        if ($resolved !== null && $resolved['department_id'] !== null) {
            $departmentName = Db::scalar(
                'SELECT name FROM departments WHERE id = ? AND clinic_id = ?',
                [$resolved['department_id'], $clinicId]
            );
        }

        if (!$clinicId) {
            abort(404, 'This screen link is not valid. Ask the clinic for a new one.');
        }

        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [(int) $clinicId]);
        if (!$clinic) {
            abort(404, 'This screen link is not valid.');
        }

        view('display/screen', [
            'title' => $departmentName !== null ? $departmentName . ' — waiting room' : 'Waiting room',
            'key' => $key,
            'clinicRecord' => $clinic,
            'queues' => [],
            'canRotate' => false,
            'publicScreen' => true,
            'departmentName' => $departmentName,
        ], false);
    }

    /**
     * Turn the kiosk on for this clinic and show the address to open on the
     * tablet.
     *
     * Issuing twice rotates: the second call replaces the key the first one
     * printed. That is the intended repair when a tablet is replaced or a key
     * is suspected to have been photographed, and it is why this action says
     * so rather than quietly succeeding.
     */
    public function kioskIssuing(): void
    {
        $clinicId = Auth::clinicId();
        if ($clinicId === null) {
            abort(403, 'Not available.');
        }
        $key = (new KioskService())->rotate($clinicId);
        (new AuditService())->log($clinicId, 'kiosk.key_issued', 'setting', null);
        flash('success', 'Kiosk ready. Open the address below on the tablet — the previous code, if any, has stopped working.');
        Session::flash('kiosk_new_key', $key);
        redirect('/display');
    }

    public function kioskRevoke(): void
    {
        $clinicId = Auth::clinicId();
        if ($clinicId === null) {
            abort(403, 'Not available.');
        }
        (new KioskService())->revoke($clinicId);
        (new AuditService())->log($clinicId, 'kiosk.key_revoked', 'setting', null);
        flash('success', 'Kiosk switched off. Any tablet holding the old address can no longer issue tokens.');
        redirect('/display');
    }

    /**
     * Patient-facing live queue — the same board the lobby TV shows, but
     * optimised for a phone screen. No session required: the receptionist
     * tells patients to open this link and watch their turn online.
     *
     * The markup pulls from /api/display/{key}, exactly the same endpoint the
     * TV uses, so a patient at home and a TV in the lobby see identical
     * content — token numbers, now-serving, room/doctor, delays, and counts.
     * Nothing here reveals a patient's name or phone number.
     */
    public function live(string $key): void
    {
        $resolved = (new DisplayKeyService())->resolve($key);
        $clinicId = $resolved['clinic_id'] ?? null;

        $departmentName = null;
        if ($resolved !== null && $resolved['department_id'] !== null) {
            $departmentName = Db::scalar(
                'SELECT name FROM departments WHERE id = ? AND clinic_id = ?',
                [$resolved['department_id'], $clinicId]
            );
        }

        if (!$clinicId) {
            abort(404, 'This queue link is not valid. Ask the clinic for a new one.');
        }

        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [(int) $clinicId]);
        if (!$clinic) {
            abort(404, 'This queue link is not valid.');
        }

        view('display/live', [
            'title' => $departmentName !== null ? $departmentName . ' — queue' : 'Queue',
            'key' => $key,
            'clinicRecord' => $clinic,
            'departmentName' => $departmentName,
        ], false);
    }

    public function rotate(): void
    {
        $clinicId = Auth::clinicId();
        if ($clinicId === null) {
            abort(403, 'Not available.');
        }
        $key = Str::urlToken(20);
        Db::run("DELETE FROM settings WHERE scope='clinic' AND owner_id=? AND key_name='display_token'", [$clinicId]);
        Db::insert("INSERT INTO settings (scope, owner_id, key_name, value) VALUES ('clinic', ?, 'display_token', ?)",
            [$clinicId, $key]);
        (new AuditService())->log($clinicId, 'display.key_rotated', 'setting', null);
        flash('success', 'Display key rotated. Any screen using the old link will stop updating.');
        redirect('/display');
    }

    /**
     * Issue a key for one screen.
     *
     * Naming the screen is the point: "Reception TV" can be revoked on its
     * own when the TV is moved, without every other screen in the clinic
     * going dark.
     */
    public function issueKey(): void
    {
        $clinicId = Auth::clinicId();
        if ($clinicId === null) {
            abort(403, 'Not available.');
            return;
        }
        if (!Auth::can('clinic.manage')) {
            abort(403, 'Only a clinic owner can issue display keys.');
            return;
        }

        $kind = Request::string('kind') === 'department' ? 'department' : 'waiting_room';
        $departmentId = Request::int('department_id') ?: null;

        try {
            $issued = (new DisplayKeyService())->issue(
                $clinicId,
                Request::string('name'),
                $kind,
                $departmentId
            );
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/settings/displays');
            return;
        }

        // Shown once, like a password. The list shows the screens, not their
        // keys, so a shoulder-surfer at the desk cannot read one off the page.
        Session::flash('issued_display_key', $issued);
        flash('success', 'Key issued for ' . $issued['name'] . '. Copy it now — it is not shown again.');
        // Back to the management page, not the TV. The TV is a wall-mounted
        // screen and would consume the one-time key display.
        redirect('/settings/displays');
    }

    /** Take one screen offline without touching the others. */
    public function revokeKey(string $id): void
    {
        $clinicId = Auth::clinicId();
        if ($clinicId === null) {
            abort(403, 'Not available.');
            return;
        }
        if (!Auth::can('clinic.manage')) {
            abort(403, 'Only a clinic owner can revoke display keys.');
            return;
        }

        $done = (new DisplayKeyService())->revoke($clinicId, (int) $id);
        flash($done ? 'success' : 'warn', $done
            ? 'Screen revoked. Any display using that link will stop updating.'
            : 'That screen was already revoked.');
        redirect('/settings/displays');
    }

    /**
     * Manage the keys that open the public screens.
     *
     * Kept out of the TV view on purpose: /display is a wall-mounted screen
     * read from six metres away, and a key list does not belong on it. The
     * PDF is also clear that advanced configuration sits inside settings
     * rather than on an everyday screen.
     */
    public function displays(): void
    {
        $clinicId = Auth::clinicId();
        if ($clinicId === null) {
            abort(403, 'Not available.');
            return;
        }

        view('display/keys', [
            'title' => 'Public screens',
            'displayKeys' => (new DisplayKeyService())->forClinic($clinicId),
            'departments' => Db::all(
                'SELECT id, name FROM departments WHERE clinic_id = ? AND is_active = 1 ORDER BY name',
                [$clinicId]
            ),
            'issuedKey' => Session::takeFlash('issued_display_key'),
            'legacyKey' => (string) Db::scalar(
                "SELECT value FROM settings WHERE scope='clinic' AND owner_id=? AND key_name='display_token'",
                [$clinicId]
            ),
        ]);
    }

    private function ensureKey(int $clinicId): string
    {
        $key = Db::scalar(
            "SELECT value FROM settings WHERE scope='clinic' AND owner_id=? AND key_name='display_token'",
            [$clinicId]
        );
        if (!$key) {
            $key = Str::urlToken(20);
            Db::insert("INSERT INTO settings (scope, owner_id, key_name, value) VALUES ('clinic', ?, 'display_token', ?)",
                [$clinicId, $key]);
        }
        return (string) $key;
    }
}
