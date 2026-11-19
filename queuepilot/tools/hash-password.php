<?php
/**
 * Print a password_hash() for a password given on the command line.
 *
 * tools/write-coverage.mjs changes a real account's password to prove the
 * write path works, then has to put it back. Rehashing through the app's own
 * hasher — rather than copying a hash from elsewhere — keeps the restored
 * account identical in cost and algorithm to one created normally.
 *
 * Usage:
 *     node tools/php.mjs tools/hash-password.php --password 'Reception#2026'
 *
 * Space-separated, not --flag=value: App\Support\Argv::value() reads the
 * element after the flag, and the `=` form returns null.
 *
 * Read through App\Support\Argv, never $argv: the php-wasm harness fills only
 * $_SERVER['argv'], so a tool reading $argv sees nothing and every flag is
 * silently ignored (tests/run.php guards against exactly that).
 *
 * The password is named by a flag rather than taken positionally because the
 * harness defines argv as ['php', <script>, ...args] — reading position 1
 * hashes the script's own filename, which produces a perfectly valid bcrypt
 * hash of the wrong string and locks the account out.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

$password = \App\Support\Argv::value('--password');

if ($password === null || $password === '') {
    echo "usage: php tools/hash-password.php --password='<password>'", PHP_EOL;
    exit(1);
}

echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;
