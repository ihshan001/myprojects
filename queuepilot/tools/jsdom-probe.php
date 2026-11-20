<?php
/**
 * Fixtures for tools/jsdom-check.mjs.
 *
 *   php tools/jsdom-probe.php                 # print {"phone":…,"key":…}
 *   php tools/jsdom-probe.php --cleanup       # remove every fixture key
 *
 * The board key has to outlive the process that mints it, because the checker
 * runs in Node afterwards — so minting and cleanup are two calls, and a run
 * that dies in between leaves a key behind. Cleanup therefore sweeps by name
 * rather than by the one key this run minted, so an interrupted run cleans
 * itself up on the next one instead of accumulating.
 *
 * Arguments go through App\Support\Argv, not $argv: the php-wasm harness does
 * not populate $argv, so a tool reading it directly sees an empty list and
 * --cleanup is silently ignored. That failure is invisible — the tool exits 0
 * and the key stays.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Db;
use App\Core\Str;
use App\Support\Argv;

const FIXTURE_NAME = 'jsdom-check fixture';

if (Argv::has('--cleanup')) {
    $removed = Db::scalar('SELECT COUNT(*) FROM public_display_keys WHERE name = ?', [FIXTURE_NAME]);
    Db::run('DELETE FROM public_display_keys WHERE name = ?', [FIXTURE_NAME]);
    echo "removed {$removed} fixture key(s)\n";
    exit(0);
}

$patient = Db::one("SELECT phone FROM patients WHERE phone IS NOT NULL AND phone <> '' ORDER BY id LIMIT 1");
$key = Str::urlToken(24);

Db::insert(
    'INSERT INTO public_display_keys (clinic_id, name, display_key, kind, created_by, created_at)
     VALUES (?,?,?,?,?,?)',
    [1, FIXTURE_NAME, $key, 'waiting_room', 1, Db::now()]
);

echo json_encode([
    'phone' => (string) ($patient['phone'] ?? ''),
    'key'   => $key,
]), "\n";
