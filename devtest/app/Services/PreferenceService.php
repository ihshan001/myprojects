<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * The switches on the settings screen that are not clinic identity:
 * which notifications go out, and the clinic's language and timezone.
 *
 * They live in the key/value `settings` table rather than in new columns on
 * `clinics`, because they are preferences a clinic changes often and reads
 * rarely. A migration per preference would be absurd; one row per preference
 * is not.
 *
 * Two rules worth stating, because both are easy to get wrong:
 *
 *   - A missing row means OFF, never ON. Absence is not consent.
 *   - Only the keys in NOTIFICATIONS are writable. The table is shared with
 *     deployment-ish values, so a whitelist keeps one form from reaching
 *     another's keys.
 */
final class PreferenceService
{
    /** @var array<int, array{key: string, name: string, blurb: string}> */
    public const NOTIFICATIONS = [
        ['key' => 'queue_alerts', 'name' => 'Queue alerts',
         'blurb' => 'Get notified about long waits and stalled queues.'],
        ['key' => 'system_updates', 'name' => 'System updates',
         'blurb' => 'Important announcements about ClinicQueue itself.'],
        ['key' => 'daily_summary', 'name' => 'Daily summary',
         'blurb' => 'Receive a daily summary of the queue at close.'],
    ];

    /** @var array<string, string> */
    private const TIMEZONES = [
        'Asia/Colombo' => '(GMT+5:30) Colombo',
        'Asia/Kolkata' => '(GMT+5:30) Kolkata',
        'Asia/Dubai'   => '(GMT+4:00) Dubai',
        'Asia/Singapore' => '(GMT+8:00) Singapore',
        'Europe/London' => '(GMT+0:00) London',
        'UTC'          => '(GMT+0:00) UTC',
    ];

    /** @var array<string, string> */
    private const LOCALES = [
        'en' => 'English',
        'si' => 'Sinhala',
        'ta' => 'Tamil',
    ];

    /**
     * The three notification switches with this clinic's current values.
     *
     * @return array<int, array<string, mixed>>
     */
    public function notifications(int $clinicId): array
    {
        $stored = $this->stored($clinicId);

        $out = [];
        foreach (self::NOTIFICATIONS as $entry) {
            $out[] = [
                'key'   => $entry['key'],
                'name'  => $entry['name'],
                'blurb' => $entry['blurb'],
                // Missing row reads as off: see the class comment.
                'on'    => ($stored['notif.' . $entry['key']] ?? '0') === '1',
            ];
        }

        return $out;
    }

    /**
     * Save the notification switches.
     *
     * Keys absent from the post are set off, not left alone — an unchecked
     * checkbox sends nothing, and "nothing sent" is the user asking for off.
     *
     * @param array<string, mixed> $input
     * @return int how many switches are on afterwards
     */
    public function saveNotifications(int $clinicId, array $input): int
    {
        $on = 0;
        foreach (self::NOTIFICATIONS as $entry) {
            $value = !empty($input[$entry['key']]) ? '1' : '0';
            if ($value === '1') {
                $on++;
            }
            $this->put($clinicId, 'notif.' . $entry['key'], $value);
        }

        return $on;
    }

    /** @return array<string, string> */
    public function timezones(): array
    {
        return self::TIMEZONES;
    }

    /** @return array<string, string> */
    public function locales(): array
    {
        return self::LOCALES;
    }

    /**
     * Save language and timezone.
     *
     * Both are validated against the lists above rather than written blind:
     * `clinics.timezone` is handed straight to PHP's DateTimeZone in the rest
     * of the app, and an unknown zone there is a fatal on every page.
     */
    public function saveRegion(int $clinicId, string $locale, string $timezone): array
    {
        $errors = [];

        if (!isset(self::LOCALES[$locale])) {
            $errors[] = 'Choose a supported language.';
        }
        if (!isset(self::TIMEZONES[$timezone])) {
            $errors[] = 'Choose a supported timezone.';
        }
        if ($errors !== []) {
            return $errors;
        }

        Db::run(
            'UPDATE clinics SET locale = ?, timezone = ?, updated_at = ? WHERE id = ?',
            [$locale, $timezone, Db::now(), $clinicId]
        );

        return [];
    }

    /**
     * Read every preference row for a clinic once.
     *
     * @return array<string, string>
     */
    private function stored(int $clinicId): array
    {
        $rows = Db::all(
            "SELECT key_name, value FROM settings WHERE scope = 'clinic' AND owner_id = ?",
            [$clinicId]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['key_name']] = (string) $row['value'];
        }

        return $out;
    }

    private function put(int $clinicId, string $key, string $value): void
    {
        $exists = Db::scalar(
            "SELECT id FROM settings WHERE scope = 'clinic' AND owner_id = ? AND key_name = ?",
            [$clinicId, $key]
        );

        if ($exists) {
            Db::run('UPDATE settings SET value = ? WHERE id = ?', [$value, $exists]);

            return;
        }

        Db::run(
            "INSERT INTO settings (scope, owner_id, key_name, value) VALUES ('clinic', ?, ?, ?)",
            [$clinicId, $key, $value]
        );
    }
}
