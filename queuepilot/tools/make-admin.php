<?php
declare(strict_types=1);

/**
 * Create or repair the first platform administrator.
 *
 * Without this the product cannot be installed. Every admin route is gated on
 * `super_admin`, and the only thing that has ever created a super admin is
 * `tools/seed.php` — which creates one *together with* five demo tenants, three
 * fake clinics and a suspended billing case. Telling an operator to "run the
 * seeder on production" to get a login means shipping demo patient data into a
 * live database, which is not a thing anyone should be asked to do.
 *
 * `seed.php --plans-only` is the production-safe path for the plans, and it
 * deliberately creates no users. That left a hole: a clean install has plans,
 * tables, and nobody who can sign in.
 *
 *   php tools/make-admin.php --email=you@example.com
 *       Creates a super admin and prints a one-time password. Reads the
 *       password from a prompt or --password= (avoid --password= on a server:
 *       it lands in shell history and in `ps`).
 *
 *   php tools/make-admin.php --email=you@example.com --reset
 *       Sets a new password on an existing admin. This is the recovery path
 *       when a 2FA device is lost: it also clears the TOTP enrolment, so the
 *       next sign-in goes to enrolment rather than a challenge nobody can
 *       answer. Use it with care — it is the one command that can take over the
 *       platform account.
 *
 *   php tools/make-admin.php --list
 *       Who the platform admins are, and whether each has enrolled 2FA.
 *
 * It never prints a password that it did not just generate, and refusing is
 * better than guessing: a typo in --email creates an account nobody owns.
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use App\Support\Argv;
use App\Core\Db;

function say(string $line = ''): void
{
    echo $line . "\n";
}

/** Random, readable, and long enough to survive a shared-host brute force. */
function generatePassword(): string
{
    // Three words plus a number: ~62 bits, and it can be read down a phone.
    $words = ['harbour', 'lantern', 'copper', 'meadow', 'quartz', 'saffron', 'tundra',
              'willow', 'citadel', 'falcon', 'granite', 'juniper', 'kestrel', 'marble',
              'nimbus', 'orchid', 'pepper', 'ripple', 'summit', 'thistle', 'velvet'];
    $picked = [];
    for ($i = 0; $i < 3; $i++) {
        $picked[] = $words[random_int(0, count($words) - 1)];
    }
    return ucfirst($picked[0]) . '-' . $picked[1] . '-' . $picked[2] . '-' . random_int(1000, 9999);
}

/* ------------------------------------------------------------------ list */

if (Argv::has('--list')) {
    $admins = Db::all("SELECT id, name, email, is_active, totp_enabled_at, last_login_at
                         FROM users WHERE role = 'super_admin' ORDER BY id");
    say('Platform administrators: ' . count($admins));
    if ($admins === []) {
        say('');
        say('  NONE. Nobody can approve a clinic or reach /admin.');
        say('  Create one with: php tools/make-admin.php --email=you@example.com');
        exit(1);
    }
    say('');
    foreach ($admins as $a) {
        say(sprintf(
            '  #%-4d %-24s %-30s %s  %s',
            $a['id'],
            mb_substr((string) $a['name'], 0, 24),
            (string) $a['email'],
            (int) $a['is_active'] === 1 ? 'active  ' : 'DISABLED',
            !empty($a['totp_enabled_at']) ? '2FA enrolled' : '2FA NOT enrolled'
        ));
        if (!empty($a['last_login_at'])) {
            say('        last signed in ' . $a['last_login_at']);
        }
    }
    exit(0);
}

/* --------------------------------------------------------------- create */

/**
 * Read `--flag=value` as well as `--flag value`.
 *
 * Argv::value() only understands the space-separated form. Every other tool in
 * this repository documents the space form, but `--email=me@example.com` is
 * what an operator types first, and silently reading it as an empty string
 * would create nothing while printing a usage message that looks like a
 * different problem.
 */
$flagValue = static function (string $flag): ?string {
    $args = Argv::all();
    foreach ($args as $arg) {
        if (str_starts_with($arg, $flag . '=')) {
            return substr($arg, strlen($flag) + 1);
        }
    }
    return Argv::value($flag);
};

$email = mb_strtolower(trim((string) ($flagValue('--email') ?? '')));
$name  = trim((string) ($flagValue('--name') ?? ''));
$reset = Argv::has('--reset');
$given = $flagValue('--password');

if ($email === '') {
    say('Usage: php tools/make-admin.php --email=you@example.com [--name="Your Name"]');
    say('       php tools/make-admin.php --email=you@example.com --reset');
    say('       php tools/make-admin.php --list');
    say('');
    say('A platform admin is the account that approves clinics and verifies');
    say('payments. Nobody can reach /admin without one.');
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    say("That is not an email address: {$email}");
    exit(1);
}

$existing = Db::one('SELECT * FROM users WHERE email = ?', [$email]);

if ($existing !== null && !$reset) {
    say("{$email} already exists (role: {$existing['role']}, id {$existing['id']}).");
    say('');
    if ($existing['role'] === 'super_admin') {
        say('To set a new password and clear two-factor, so you can sign in again:');
        say("  php tools/make-admin.php --email={$email} --reset");
    } else {
        say('That account belongs to a clinic. Platform admins are separate accounts');
        say('with no clinic — do not convert a clinic owner into one.');
    }
    exit(1);
}

if ($existing !== null && $reset && $existing['role'] !== 'super_admin') {
    say("Refusing: {$email} is a {$existing['role']}, not a platform admin.");
    say('--reset only repairs a platform account, so a typo cannot overwrite a clinic owner.');
    exit(1);
}

if (is_string($given) && $given !== '') {
    $password = $given;
    $generated = false;
} elseif ($reset) {
    $password = generatePassword();
    $generated = true;
} else {
    $password = generatePassword();
    $generated = true;
}

$min = (int) \App\Core\Env::get('security.password_min', 10);
if (mb_strlen($password) < $min) {
    say("The password must be at least {$min} characters.");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

if ($existing !== null) {
    // A reset clears the second factor as well. Somebody who lost their phone
    // cannot complete a challenge, and leaving the old secret in place means
    // the new password is still followed by a prompt they cannot answer.
    Db::run(
        'UPDATE users SET password_hash = ?, must_change_password = 1, is_active = 1,
                totp_secret = NULL, totp_enabled_at = NULL, totp_recovery = NULL
          WHERE id = ?',
        [$hash, (int) $existing['id']]
    );
    \App\Core\Log::warn('platform.admin_password_reset', ['user' => (int) $existing['id'], 'email' => $email]);
    say('');
    say('Password reset for ' . $existing['name'] . ' <' . $email . '>');
    say('Two-factor was cleared — the next sign-in will ask to enrol again.');
} else {
    $displayName = $name !== '' ? $name : 'Platform Admin';
    $id = Db::insert(
        'INSERT INTO users (clinic_id, role, name, email, password_hash, locale, is_active,
                            email_verified_at, must_change_password, created_at)
         VALUES (NULL, ?, ?, ?, ?, ?, 1, ?, 1, ?)',
        ['super_admin', $displayName, $email, $hash, 'en', Db::now(), Db::now()]
    );
    \App\Core\Log::info('platform.admin_created', ['user' => (int) $id, 'email' => $email]);
    say('');
    say('Created platform admin #' . $id . ' — ' . $displayName . ' <' . $email . '>');
}

say('');
say('┌────────────────────────────────────────────────────────────┐');
say('│  Sign in, then set your own password and enrol two-factor.  │');
say('└────────────────────────────────────────────────────────────┘');
if ($generated) {
    say('');
    say('  ONE-TIME PASSWORD:   ' . $password);
    say('');
    say('  This is not shown again. It is a starting credential, not a');
    say('  permanent one: you will be asked to change it on first sign-in,');
    say('  and platform admins must enrol an authenticator app before any');
    say('  admin screen opens.');
} else {
    say('');
    say('  Password: as supplied with --password.');
    say('  (Passing it on the command line leaves it in shell history and in');
    say('   the process list. Prefer letting this tool generate one.)');
}
say('');
say('  Sign in at:  ' . rtrim((string) \App\Core\Env::get('app.url', ''), '/') . '/login');
say('  Console:    ' . rtrim((string) \App\Core\Env::get('app.url', ''), '/') . '/admin');
say('');
say('Next, if you have not already:');
say('  php tools/seed.php --plans-only     # the four subscription plans, no demo data');
say('  php tools/host-drill.php            # is this host configured correctly?');
