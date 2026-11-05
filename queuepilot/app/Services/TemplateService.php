<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Message wording a clinic can change without a deploy.
 *
 * The shipped copy in `NotificationService::TEMPLATES` remains the default and
 * the reference: a clinic overrides one template at a time, and anything it
 * has not touched keeps receiving improvements from later releases. That is
 * the whole point of overriding rather than copying — a clinic that edits the
 * "called" message should still get a fixed "reminder" message next month.
 *
 * Validation exists because these strings go to patients:
 *
 *   * A template that **drops** `{token}` is a message that never tells the
 *     patient their number. Refused, with the placeholder named.
 *   * A template that contains `{toke}` sends the literal braces to a
 *     patient. Refused, because it is a typo the sender can still see.
 *   * A template that is empty, or longer than three SMS segments, is refused
 *     — the first is not a message and the second is a bill nobody agreed to.
 *
 * All of it happens when a person presses Save. A message that fails at 03:00
 * in the worker is a patient who was never told anything.
 */
final class TemplateService
{
    /**
     * The placeholders each template actually supports.
     *
     * Declared rather than discovered from the default text: the defaults do
     * not mention every variable a clinic might legitimately want (a clinic
     * may reasonably add {when} to the "called" message), and a validator that
     * reads its own defaults would forbid that.
     */
    public const ALLOWED = [
        'token_issued'        => ['token', 'queue', 'clinic', 'link', 'room', 'ahead', 'when'],
        'entry_called'        => ['token', 'queue', 'clinic', 'link', 'room', 'when'],
        'entry_approaching'   => ['token', 'queue', 'clinic', 'ahead', 'when'],
        'visit_completed'     => ['token', 'queue', 'clinic', 'when'],
        'appointment_reminder' => ['when', 'clinic', 'token', 'queue', 'room', 'link'],
        'entry_delayed'       => ['token', 'queue', 'clinic', 'minutes', 'when'],
    ];

    /**
     * Placeholders that must appear, because without them the message does
     * not do its job. A "called" message that omits the token tells a patient
     * to come in without telling them who they are.
     */
    public const REQUIRED = [
        'token_issued'        => ['token'],
        'entry_called'        => ['token'],
        'entry_approaching'   => ['token'],
        'visit_completed'     => [],
        'appointment_reminder' => ['when'],
        'entry_delayed'       => ['minutes'],
    ];

    /** Human labels for the settings screen. */
    public const LABELS = [
        'token_issued'         => 'Token issued',
        'entry_called'         => 'Your turn now',
        'entry_approaching'    => 'Almost your turn',
        'visit_completed'      => 'Visit finished',
        'appointment_reminder' => 'Appointment reminder',
        'entry_delayed'        => 'Doctor running late',
    ];

    /** Three GSM segments is ~480 characters; beyond that a bill is a surprise. */
    private const MAX_LENGTH = 480;

    /**
     * The template this clinic should use: its own if it has one, otherwise
     * the shipped default.
     */
    public function body(int $clinicId, string $template): string
    {
        $override = Db::scalar(
            'SELECT body FROM notification_templates WHERE clinic_id = ? AND template = ?',
            [$clinicId, $template]
        );
        if ($override !== null && trim((string) $override) !== '') {
            return (string) $override;
        }
        return (string) (NotificationService::TEMPLATES[$template] ?? '');
    }

    /**
     * Every template with its effective text and whether it is customised.
     *
     * @return list<array{key:string,label:string,body:string,default:string,customised:bool,allowed:list<string>,required:list<string>}>
     */
    public function all(int $clinicId): array
    {
        $overrides = [];
        foreach (Db::all('SELECT template, body FROM notification_templates WHERE clinic_id = ?', [$clinicId]) as $row) {
            $overrides[(string) $row['template']] = (string) $row['body'];
        }

        $out = [];
        foreach (NotificationService::TEMPLATES as $key => $default) {
            $out[] = [
                'key' => $key,
                'label' => self::LABELS[$key] ?? $key,
                'body' => $overrides[$key] ?? $default,
                'default' => $default,
                'customised' => isset($overrides[$key]),
                'allowed' => self::ALLOWED[$key] ?? [],
                'required' => self::REQUIRED[$key] ?? [],
            ];
        }
        return $out;
    }

    /**
     * Validate then save one template.
     *
     * @throws \InvalidArgumentException with a message written for a clinic
     *         manager, not a developer
     */
    public function save(int $clinicId, string $template, string $body, ?int $actorId = null): string
    {
        if (!isset(NotificationService::TEMPLATES[$template])) {
            throw new \InvalidArgumentException('That is not a message this clinic sends.');
        }

        $clean = trim($body);
        if ($clean === '') {
            throw new \InvalidArgumentException('Write the message, or reset it to the default.');
        }
        if (mb_strlen($clean) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'That message is %d characters. Keep it under %d — beyond that it is split into several texts '
                . 'and the clinic pays for each one.',
                mb_strlen($clean),
                self::MAX_LENGTH
            ));
        }

        $allowed = self::ALLOWED[$template] ?? [];
        preg_match_all('/\{([a-z_]+)\}/', $clean, $matches);
        $used = array_values(array_unique($matches[1] ?? []));

        $unknown = array_values(array_diff($used, $allowed));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'This message uses {%s}, which is not filled in for this message. Available here: %s.',
                implode('}, {', $unknown),
                $allowed === [] ? 'none' : '{' . implode('}, {', $allowed) . '}'
            ));
        }

        $missing = array_values(array_diff(self::REQUIRED[$template] ?? [], $used));
        if ($missing !== []) {
            throw new \InvalidArgumentException(sprintf(
                'This message has to include {%s} — without it the patient is told something without the '
                . 'detail they need.',
                implode('} and {', $missing)
            ));
        }

        // A stray brace that is not a placeholder: "{token" or "token}" would
        // reach the patient literally. Detected by stripping the valid ones.
        $stripped = preg_replace('/\{[a-z_]+\}/', '', $clean) ?? '';
        if (str_contains($stripped, '{') || str_contains($stripped, '}')) {
            throw new \InvalidArgumentException(
                'There is an unmatched brace in that message. Placeholders look like {token}.'
            );
        }

        $exists = Db::scalar(
            'SELECT id FROM notification_templates WHERE clinic_id = ? AND template = ?',
            [$clinicId, $template]
        );
        if ($exists !== null) {
            Db::run(
                'UPDATE notification_templates SET body = ?, updated_by = ?, updated_at = ? WHERE id = ?',
                [$clean, $actorId, Db::now(), (int) $exists]
            );
        } else {
            Db::insert(
                'INSERT INTO notification_templates (clinic_id, template, body, updated_by, created_at, updated_at)
                 VALUES (?,?,?,?,?,?)',
                [$clinicId, $template, $clean, $actorId, Db::now(), Db::now()]
            );
        }

        (new AuditService())->log($clinicId, 'notification.template_saved', 'setting', null, [
            'template' => $template,
        ]);

        return $clean;
    }

    /** Forget an override, so the clinic goes back to the shipped wording. */
    public function reset(int $clinicId, string $template): bool
    {
        $deleted = Db::run(
            'DELETE FROM notification_templates WHERE clinic_id = ? AND template = ?',
            [$clinicId, $template]
        )->rowCount();
        if ($deleted > 0) {
            (new AuditService())->log($clinicId, 'notification.template_reset', 'setting', null, [
                'template' => $template,
            ]);
        }
        return $deleted > 0;
    }

    /**
     * A preview with realistic values, so a manager sees the message a patient
     * will actually receive rather than a row of braces.
     *
     * @return array{body:string,length:int,segments:int}
     */
    public function preview(int $clinicId, string $template, string $body): array
    {
        $filled = strtr($body, [
            '{token}' => 'A014',
            '{queue}' => 'OPD',
            '{clinic}' => (string) (Db::scalar('SELECT name FROM clinics WHERE id = ?', [$clinicId]) ?? 'Your clinic'),
            '{link}' => 'https://example.lk/t/abcd1234',
            '{room}' => 'Room 2',
            '{ahead}' => '3 patients',
            '{when}' => 'Fri 6 Nov, 9:30 AM',
            '{minutes}' => '20',
        ]);

        $length = mb_strlen($filled);
        return [
            'body' => $filled,
            'length' => $length,
            // GSM-7 is 160 characters per segment, 153 once concatenated.
            'segments' => $length <= 160 ? 1 : (int) ceil($length / 153),
        ];
    }
}
