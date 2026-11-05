<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Str;
use App\Services\AuditService;

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
        $clinicId = Db::scalar(
            "SELECT owner_id FROM settings WHERE scope='clinic' AND key_name='display_token' AND value = ?",
            [$key]
        );
        if (!$clinicId) {
            abort(404, 'This screen link is not valid. Ask the clinic for a new one.');
        }

        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [(int) $clinicId]);
        if (!$clinic) {
            abort(404, 'This screen link is not valid.');
        }

        view('display/screen', [
            'title' => 'Waiting room',
            'key' => $key,
            'clinicRecord' => $clinic,
            'queues' => [],
            'canRotate' => false,
            'publicScreen' => true,
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
