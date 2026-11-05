<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Services\AuditService;

/**
 * Queue settings.
 *
 * A queue was created for a doctor each morning with a name derived from that
 * doctor ("OPD — Nuwan Perera"), a prefix taken from the clinic or the doctor's
 * initial, and the kind hard-coded to `consultation`. After that, nothing. A
 * laboratory could not run a lab queue without it being called "OPD", a
 * pharmacy counter could not issue a different token series, and a clinic that
 * wanted "Counter 1" instead of "OPD — Ruwan Jayasinghe" had no way to say so.
 *
 * This screen edits the **pattern**, not one day's queue. Tomorrow's queues are
 * created from these settings, which is the only place a change can actually
 * take effect: renaming this morning's queue would break the labels already in
 * patients' hands.
 *
 * The rules:
 *
 *   * A prefix is one to three letters. It goes in front of every token number
 *     and is read aloud, so it must be sayable.
 *   * Changing a prefix does not renumber existing tokens. Patients holding
 *     "A012" keep it; tomorrow starts at "B001" if the prefix changed. That is
 *     the least surprising behaviour, and it avoids two people holding A012.
 *   * Kind is one of the four the schema allows, with the schema's check
 *     constraint as the final word.
 */
final class QueueConfigController
{
    private const KINDS = [
        'consultation' => 'Consultation — a doctor sees patients',
        'lab'          => 'Laboratory — samples and results',
        'pharmacy'     => 'Pharmacy — dispensing counter',
        'general'      => 'General service — anything else',
    ];

    private function clinicId(): int
    {
        $id = Auth::clinicId();
        if ($id === null) {
            abort(403, 'Queue settings belong to a clinic.');
        }
        return $id;
    }

    public function index(): void
    {
        $clinicId = $this->clinicId();

        view('queues/config', [
            'title' => 'Queue settings',
            'settings' => [
                'name_pattern' => (string) (Db::scalar(
                    "SELECT value FROM settings WHERE scope='clinic' AND owner_id=? AND key_name='queue.name_pattern'",
                    [$clinicId]
                ) ?? 'OPD — {doctor}'),
                'kind'         => (string) (Db::scalar(
                    "SELECT value FROM settings WHERE scope='clinic' AND owner_id=? AND key_name='queue.default_kind'",
                    [$clinicId]
                ) ?? 'consultation'),
                'prefix'       => (string) (Db::scalar(
                    "SELECT value FROM settings WHERE scope='clinic' AND owner_id=? AND key_name='queue.default_prefix'",
                    [$clinicId]
                ) ?? ''),
                'padding'      => (int) (Db::scalar(
                    "SELECT value FROM settings WHERE scope='clinic' AND owner_id=? AND key_name='queue.token_padding'",
                    [$clinicId]
                ) ?? 3),
            ],
            'kinds' => self::KINDS,
            // What the next queue would be called, so the pattern is not a riddle.
            'sampleDoctor' => (string) (Db::scalar(
                'SELECT name FROM doctors WHERE clinic_id = ? AND is_active = 1 ORDER BY id LIMIT 1',
                [$clinicId]
            ) ?: 'Dr. Sample'),
            'todayQueues' => Db::all(
                'SELECT id, name, kind, token_prefix, last_token_no, token_padding, status
                   FROM queues WHERE clinic_id = ? AND session_date = ? ORDER BY id',
                [$clinicId, Db::today()]
            ),
            'canManage' => Auth::can('clinic.manage'),
            'active' => 'queues',
        ]);
    }

    public function save(): void
    {
        $clinicId = $this->clinicId();
        if (!Auth::can('clinic.manage')) {
            abort(403, 'Only a clinic owner can change queue settings.');
        }

        $pattern = trim(Request::string('name_pattern', 'OPD — {doctor}'));
        $kind = Request::string('kind', 'consultation');
        $prefix = strtoupper(trim(Request::string('prefix')));
        $padding = Request::int('padding', 3);

        $errors = [];

        if (mb_strlen($pattern) < 1 || mb_strlen($pattern) > 60) {
            $errors[] = 'The queue name pattern needs to be between 1 and 60 characters.';
        }
        // The placeholder is the contract: without it every doctor's queue gets
        // the same name, and the board cannot tell them apart.
        if (!str_contains($pattern, '{doctor}') && !str_contains($pattern, '{n}')) {
            $errors[] = 'Include {doctor} (the doctor\'s name) or {n} (a counter number) so each queue gets a distinct name.';
        }
        if (!isset(self::KINDS[$kind])) {
            $errors[] = 'Choose one of the queue types.';
        }
        if ($prefix !== '' && preg_match('/^[A-Z]{1,3}$/', $prefix) !== 1) {
            $errors[] = 'A token prefix is one to three letters — it is read aloud to patients.';
        }
        if ($padding < 1 || $padding > 6) {
            $errors[] = 'Token padding is between 1 and 6 digits.';
        }

        if ($errors !== []) {
            foreach ($errors as $message) {
                flash('error', $message);
            }
            redirect('/queues/config');
            return;
        }

        $this->put($clinicId, 'queue.name_pattern', $pattern);
        $this->put($clinicId, 'queue.default_kind', $kind);
        $this->put($clinicId, 'queue.default_prefix', $prefix);
        $this->put($clinicId, 'queue.token_padding', (string) $padding);

        (new AuditService())->log($clinicId, 'queue.settings_updated', 'clinic', $clinicId, [
            'name_pattern' => $pattern, 'kind' => $kind, 'prefix' => $prefix, 'padding' => $padding,
        ]);

        flash('success', 'Saved. Queues opened from tomorrow use these settings; queues already open today keep their labels.');
        redirect('/queues/config');
    }

    /**
     * Rename one queue that has not issued any tokens yet.
     *
     * Deliberately narrow: once a patient holds a token with a label, changing
     * the queue's name underneath them makes the display and the slip disagree.
     * An untouched queue is safe to rename, and that is the common case when a
     * session is opened with the wrong pattern.
     */
    public function rename(string $id): void
    {
        $clinicId = $this->clinicId();
        if (!Auth::can('clinic.manage')) {
            abort(403, 'Only a clinic owner can rename a queue.');
        }

        $queue = Db::one('SELECT * FROM queues WHERE id = ? AND clinic_id = ?', [(int) $id, $clinicId]);
        if (!$queue) {
            abort(404, 'That queue does not exist in this clinic.');
            return;
        }

        if ((int) $queue['last_token_no'] > 0) {
            flash('error', sprintf(
                '%s has already issued %d token%s. Renaming it now would make the slip in a patient\'s hand disagree with the screen. Wait until tomorrow.',
                $queue['name'],
                (int) $queue['last_token_no'],
                (int) $queue['last_token_no'] === 1 ? '' : 's'
            ));
            redirect('/queues/config');
            return;
        }

        $name = trim(Request::string('name'));
        if ($name === '' || mb_strlen($name) > 60) {
            flash('error', 'Give the queue a name of 1 to 60 characters.');
            redirect('/queues/config');
            return;
        }

        Db::run('UPDATE queues SET name = ? WHERE id = ? AND clinic_id = ?', [$name, (int) $id, $clinicId]);
        (new AuditService())->log($clinicId, 'queue.renamed', 'queue', (int) $id, [
            'from' => $queue['name'], 'to' => $name,
        ]);

        flash('success', 'Renamed. Nothing had been issued from that queue yet, so no patient is affected.');
        redirect('/queues/config');
    }

    private function put(int $clinicId, string $key, string $value): void
    {
        Db::run(
            "INSERT INTO settings (scope, owner_id, key_name, value) VALUES ('clinic', ?, ?, ?)
             ON CONFLICT (scope, owner_id, key_name) DO UPDATE SET value = excluded.value",
            [$clinicId, $key, $value]
        );
    }
}
