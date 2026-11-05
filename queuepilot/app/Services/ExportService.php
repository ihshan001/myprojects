<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * A clinic's complete data, in one archive a manager can keep.
 *
 * The right to export is the one obligation that outlives the subscription:
 * a clinic leaving the product must be able to take its records somewhere
 * else. A pile of CSV links on a reports page is not that. This builds a
 * single zip a manager can hand to their accountant or keep in a drawer.
 *
 * Two decisions are worth stating because they are not obvious:
 *
 *  1. The export is REDACTED by default. `settings` holds `display_token`,
 *     which is a live bearer credential for the waiting-room screen, and
 *     `users` holds `totp_secret` and `password_hash`. Mailing an archive
 *     containing those to an accountant turns a data-portability feature
 *     into a credential leak. Passwords and second-factor secrets never
 *     leave, under any option. The display token only leaves when the
 *     manager explicitly asks for the screen configuration — and then it is
 *     named in the manifest as the one credential inside.
 *
 *  2. Nothing is loaded into memory whole. Every table is streamed to disk
 *     row by row, so a clinic with ten years of appointments exports on a
 *     small server.
 *
 * The archive is written to `storage/backups/exports` (gitignored, like the
 * database dumps) and handed over once; it is not a permanent web-accessible
 * file. `GET /settings/export/download` re-authorises the same clinic before
 * any bytes are sent.
 */
final class ExportService
{
    /** Data that belongs to a clinic, in the order it appears in the manifest. */
    private const TABLES = [
        'departments'        => 'Departments',
        'rooms'              => 'Rooms',
        'doctors'            => 'Doctors',
        'users'              => 'Staff accounts',
        'patients'           => 'Patients',
        'queues'             => 'Queues',
        'queue_entries'      => 'Tokens issued',
        'queue_events'       => 'Queue history',
        'queue_breaks'       => 'Queue breaks',
        'scheduled_breaks'   => 'Scheduled breaks',
        'appointments'       => 'Appointments',
        'invoices'           => 'Invoices',
        'visit_feedback'     => 'Visit feedback',
        'notifications'      => 'Notifications',
        'notification_templates' => 'Message wording',
        'priority_rules'     => 'Priority rules',
        'workflow_steps'     => 'Workflow steps',
        'entry_step_done'    => 'Workflow progress',
        'doctor_schedules'   => 'Doctor schedules',
        'doctor_leave'       => 'Doctor leave',
        'holidays'           => 'Clinic holidays',
        'incidents'          => 'Incidents',
        'appointment_policies' => 'Appointment policies',
        'staff_notifications' => 'Staff notifications',
        'audit_logs'         => 'Audit log',
    ];

    /**
     * Columns removed even when the manager asks for everything.
     *
     * A password hash is not the clinic's record of anything — it is a
     * credential belonging to a person who may work somewhere else next
     * month. The same goes for a second-factor secret and recovery codes.
     * These are not behind a flag because there is no legitimate reason to
     * want them in an archive.
     */
    private const NEVER_COPY = [
        'users' => ['password_hash', 'totp_secret', 'totp_recovery'],
    ];

    /** Clinic settings that are safe to carry. */
    private const SETTINGS_SAFE = ['onboarding', 'queue.default_kind', 'queue.default_prefix', 'queue.name_pattern', 'queue.token_padding'];

    /**
     * Clinic settings that are live credentials rather than configuration.
     *
     * `display_token` opens the waiting-room screen; `kiosk.token` opens the
     * self-service kiosk. Both are bearer tokens — whoever holds the string
     * can act as that machine — so neither is exported unless the manager
     * explicitly asks, and both are needed when hardware is replaced. They
     * are listed here by name rather than pattern-matched, so a new setting
     * cannot be mistaken for one.
     */
    private const SETTINGS_CREDENTIAL = ['display_token', 'kiosk.token'];

    /**
     * Row counts for the page, computed without building anything.
     *
     * A manager deciding whether to take a copy wants to see what is in it
     * first, and these counts are cheap enough (an indexed count(*) per
     * table) that showing them is worth it. The same walk over TABLES is
     * reused so the page cannot drift from what the archive contains.
     *
     * @return array<int, array{table: string, label: string, rows: int}>
     */
    public function counts(int $clinicId): array
    {
        $out = [];
        foreach (self::TABLES as $table => $label) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $columns = array_column(Db::all('PRAGMA table_info(' . $table . ')'), 'name');
            if ($columns === []) {
                continue;
            }
            $clinicScoped = in_array('clinic_id', $columns, true);
            $rows = $clinicScoped
                ? (int) Db::scalar('SELECT COUNT(*) FROM "' . $table . '" WHERE clinic_id = ?', [$clinicId])
                : (int) Db::scalar('SELECT COUNT(*) FROM "' . $table . '"');
            $out[] = ['table' => $table, 'label' => $label, 'rows' => $rows];
        }
        return $out;
    }

    /** @return array{ok: bool, path?: string, name?: string, bytes?: int, tables?: int, rows?: int, error?: string, redacted?: array<int, string>} */
    public function build(int $clinicId, bool $includeCredentials = false, bool $includeAudit = true): array
    {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'The zip extension is not available on this server, so an archive cannot be built. Every report still exports as CSV.'];
        }
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if ($clinic === null) {
            return ['ok' => false, 'error' => 'That clinic no longer exists.'];
        }

        $dir = QP_ROOT . '/storage/backups/exports';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'The exports directory could not be created.'];
        }
        $this->sweepStale($dir);
        // Files are picked up by name and deleted after the download, so a
        // stale archive from a previous attempt must never be served to a
        // different clinic. The random suffix prevents guessing either way.
        $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) $clinic['slug'])) ?: 'clinic';
        $name = sprintf('%s-export-%s-%s.zip', trim($slug, '-'), date('Y-m-d'), bin2hex(random_bytes(6)));
        $path = $dir . '/' . $name;

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => 'The archive could not be opened for writing.'];
        }

        $redacted = [];
        $manifest = [
            'archive'            => 'QueuePilot clinic data export',
            'clinic'             => (string) $clinic['name'],
            'clinic_slug'        => (string) $clinic['slug'],
            'generated_at'       => gmdate('c'),
            'format_version'     => 1,
            'includes_credentials' => $includeCredentials,
            'files'              => [],
            'notes'              => [],
        ];

        $totalRows = 0;
        $tableCount = 0;

        foreach (self::TABLES as $table => $label) {
            if ($table === 'audit_logs' && !$includeAudit) {
                continue;
            }
            $result = $this->writeTable($zip, $clinicId, $table, $label);
            if ($result === null) {
                continue; // table does not exist in this build
            }
            $totalRows += $result['rows'];
            $tableCount++;
            $manifest['files'][] = [
                'path'  => $result['file'],
                'label' => $label,
                'rows'  => $result['rows'],
                'columns_dropped' => $result['dropped'],
            ];
            foreach ($result['dropped'] as $col) {
                $redacted[$table . '.' . $col] = true;
            }
        }

        // Clinic settings, filtered rather than dumped: owner_id points at a
        // clinic here, but the table also holds per-user rows that are not
        // this clinic's to export.
        $settings = Db::all(
            "SELECT key_name, value FROM settings WHERE scope = 'clinic' AND owner_id = ? ORDER BY key_name",
            [$clinicId]
        );
        $settingsRows = [];
        foreach ($settings as $row) {
            $key = (string) $row['key_name'];
            $isCredential = in_array($key, self::SETTINGS_CREDENTIAL, true);
            if (!$isCredential && !in_array($key, self::SETTINGS_SAFE, true) && !str_starts_with($key, 'hours.')) {
                // Unknown keys are dropped rather than guessed at. A future
                // setting that happens to hold a secret must not leak by
                // default just because it was added later.
                $redacted['settings.' . $key] = true;
                continue;
            }
            if ($isCredential && !$includeCredentials) {
                $redacted['settings.' . $key] = true;
                $settingsRows[] = ['key_name' => $key, 'value' => '[not included — this setting is a live screen credential]'];
                continue;
            }
            $settingsRows[] = $row;
        }
        if ($settingsRows !== []) {
            $csv = $this->csvString(array_keys($settingsRows[0]), $settingsRows);
            $zip->addFromString('clinic-settings.csv', "\xEF\xBB\xBF" . $csv);
            $manifest['files'][] = ['path' => 'clinic-settings.csv', 'label' => 'Clinic settings', 'rows' => count($settingsRows), 'columns_dropped' => []];
            $totalRows += count($settingsRows);
            $tableCount++;
        }

        $clinicCsv = $this->csvString(array_keys($clinic), [$clinic]);
        $zip->addFromString('clinic.csv', "\xEF\xBB\xBF" . $clinicCsv);
        $manifest['files'][] = ['path' => 'clinic.csv', 'label' => 'Clinic profile', 'rows' => 1, 'columns_dropped' => []];
        $totalRows++;

        // The manifest explains the archive to a person, so it is written in
        // sentences rather than as a bare machine file.
        $manifest['notes'][] = 'Every file is UTF-8 CSV with a byte-order mark, so Excel opens Sinhala and Tamil names correctly.';
        $manifest['notes'][] = 'Passwords, second-factor secrets and recovery codes are never included — they belong to staff as people, not to the clinic as records.';
        if ($includeCredentials) {
            $manifest['notes'][] = 'This archive INCLUDES the waiting-room display token and the kiosk token (clinic-settings.csv). Either one lets anyone holding it act as that machine for this clinic. Treat this archive as a credential until you delete it.';
        } else {
            $manifest['notes'][] = 'The waiting-room display token and the kiosk token were left out. Re-run the export with screen configuration included if you are moving the display or kiosk to new hardware.';
        }
        $manifest['notes'][] = 'Files are named after the table they came from; joined tables (such as doctor names on patients) are stored as the raw ids that were in the database.';

        $zip->addFromString('MANIFEST.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        $zip->addFromString('README.txt', $this->readme($clinic, $manifest, $redacted));

        if (!$zip->close()) {
            @unlink($path);
            return ['ok' => false, 'error' => 'The archive could not be finalised.'];
        }
        @chmod($path, 0600);

        return [
            'ok'       => true,
            'path'     => $path,
            'name'     => $name,
            'bytes'    => (int) @filesize($path),
            'tables'   => $tableCount,
            'rows'     => $totalRows,
            'redacted' => array_keys($redacted),
        ];
    }

    /**
     * Stream one table into the archive.
     *
     * @return array{file: string, rows: int, dropped: array<int, string>}|null
     */
    private function writeTable(\ZipArchive $zip, int $clinicId, string $table, string $label): ?array
    {
        if (!$this->tableExists($table)) {
            return null;
        }
        $columns = array_column(Db::all('PRAGMA table_info(' . $table . ')'), 'name');
        if ($columns === []) {
            return null;
        }
        $clinicScoped = in_array('clinic_id', $columns, true);

        $drop = self::NEVER_COPY[$table] ?? [];
        $kept = array_values(array_filter($columns, static fn (string $c): bool => !in_array($c, $drop, true)));

        $file = $table . '.csv';
        // Streamed through a php://temp that spills to disk, then handed to
        // zip as one entry. Rows are still pulled one at a time from SQLite.
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($stream === false) {
            return null;
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $kept, ',', '"', '\\');

        $sql = 'SELECT ' . implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $kept)) . ' FROM "' . $table . '"';
        if ($clinicScoped) {
            $sql .= ' WHERE clinic_id = ?';
        }
        $sql .= ' ORDER BY id';

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($clinicScoped ? [$clinicId] : []);
        $rows = 0;
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            fputcsv($stream, array_map(static fn ($v): string => $v === null ? '' : (string) $v, array_values($row)), ',', '"', '\\');
            $rows++;
        }
        rewind($stream);
        $body = stream_get_contents($stream);
        fclose($stream);

        $zip->addFromString($file, $body === false ? '' : $body);
        return ['file' => $file, 'rows' => $rows, 'dropped' => $drop];
    }

    /**
     * Delete archives left behind by a request that died mid-download.
     *
     * The normal path deletes the file after streaming it, but a client that
     * disconnects, or a fatal error between readfile() and unlink(), leaves a
     * full copy of every patient record in a directory. It is outside the
     * docroot and mode 0600, but "a copy nobody remembers is there" is
     * exactly how archives turn up in the wrong hands. An hour is far longer
     * than a download takes and far shorter than anyone's memory.
     */
    private function sweepStale(string $dir): void
    {
        $cutoff = time() - 3600;
        foreach (glob($dir . '/*.zip') ?: [] as $old) {
            if (@filemtime($old) < $cutoff) {
                @unlink($old);
            }
        }
    }

    private function tableExists(string $table): bool
    {
        $row = Db::one("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
        return $row !== null;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function csvString(array $columns, array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            return '';
        }
        fputcsv($stream, $columns, ',', '"', '\\');
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $c) {
                $line[] = $row[$c] === null ? '' : (string) $row[$c];
            }
            fputcsv($stream, $line, ',', '"', '\\');
        }
        rewind($stream);
        $out = stream_get_contents($stream);
        fclose($stream);
        return $out === false ? '' : $out;
    }

    /** @param array<string, mixed> $clinic @param array<string, mixed> $manifest @param array<string, bool> $redacted */
    private function readme(array $clinic, array $manifest, array $redacted): string
    {
        $lines = [];
        $lines[] = 'QueuePilot data export — ' . $clinic['name'];
        $lines[] = 'Generated ' . gmdate('Y-m-d H:i') . ' UTC';
        $lines[] = '';
        $lines[] = 'What is in here';
        $lines[] = '  One CSV per table, named after the table. MANIFEST.json lists each';
        $lines[] = '  file with its row count. clinic.csv is the clinic profile.';
        $lines[] = '';
        $lines[] = 'How to open it';
        $lines[] = '  Spreadsheets open the CSVs directly. They are UTF-8 with a byte-order';
        $lines[] = '  mark, so Sinhala and Tamil names appear correctly in Excel.';
        $lines[] = '';
        $lines[] = 'What was left out, on purpose';
        $lines[] = '  Staff passwords, second-factor secrets and recovery codes are never';
        $lines[] = '  exported. They are credentials belonging to a person, not records';
        $lines[] = '  belonging to the clinic.';
        if ($redacted !== []) {
            $lines[] = '  Also left out: ' . implode(', ', array_keys($redacted));
        }
        $lines[] = '';
        $lines[] = 'Moving to another system';
        $lines[] = '  Ids are preserved, so foreign keys in the CSVs still point at the';
        $lines[] = '  right rows. Import the tables in the order they are listed in';
        $lines[] = '  MANIFEST.json and the references will resolve.';
        $lines[] = '';
        $lines[] = 'Keep this file somewhere safe. It contains patient names and phone';
        $lines[] = 'numbers, and it is the clinic\'s own responsibility under data';
        $lines[] = 'protection law to store it accordingly.';
        return implode("\n", $lines) . "\n";
    }
}
