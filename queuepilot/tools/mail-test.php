<?php
declare(strict_types=1);

/**
 * Send one test message and show the whole SMTP conversation.
 *
 * Mail is the one part of this system whose failure is invisible: the app
 * carries on, the welcome email never arrives, and you find out when a clinic
 * calls to say they never got their password link. So this prints every line
 * the server said, including the ones that mean "rejected".
 *
 *   php tools/mail-test.php you@example.com
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Env;
use App\Services\MailService;

// First non-flag argument after the script name is the address, so
// `mail-test you@example.com` works without ceremony. Skipping both of the
// first two entries matters: argv[0] is the interpreter and argv[1] is this
// file, and taking argv[1] as the address is how this first shipped.
$to = '';
foreach (array_slice(App\Support\Argv::all(), 2) as $arg) {
    if (!str_starts_with($arg, '-')) {
        $to = trim($arg);
        break;
    }
}
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    err("Usage: php tools/mail-test.php you@example.com\n");
    exit(1);
}

$transport = (string) Env::get('mail.transport', 'none');
$host      = (string) Env::get('mail.host', '');
$port      = (int) Env::get('mail.port', 587);
$user      = (string) Env::get('mail.username', '');
$from      = (string) Env::get('mail.from', '');

echo "Transport: {$transport}\n";
if ($transport === 'smtp') {
    echo "Host:      {$host}:{$port}\n";
    echo "User:      " . ($user !== '' ? $user : '(none — will not authenticate)') . "\n";
    echo "From:      {$from}\n";
    echo "Password:  " . (Env::get('mail.password', '') !== '' ? '(set)' : '(EMPTY — Gmail will reject this)') . "\n";

    if ($host === '' || $port === 0) {
        err("\nMAIL_HOST / MAIL_PORT are not set. Nothing to connect to.\n");
        exit(1);
    }
    // Check reachability separately, so a firewall block reads as a firewall
    // block rather than as a wrong password. Shared hosts block 587 often
    // enough that this is the first thing to rule out.
    $probe = @fsockopen($host, $port, $errno, $errstr, 10);
    if ($probe === false) {
        err("\nCannot open a connection to {$host}:{$port} — {$errstr} ({$errno}).\n");
        err("This is a network problem, not a credentials problem.\n");
        err("  * Shared hosting often blocks outbound 587. Ask the host to open it,\n");
        err("    or try port 2525.\n");
        err("  * Check the hostname resolves: dig {$host}\n");
        exit(1);
    }
    fclose($probe);
    echo "Reachable: yes\n";
}
echo "\n";

$mail = new MailService();
// MailService::send() returns a plain bool: it logged or it did not. The
// reason lives in storage/logs/app.log, which is where the operator should
// look — so point at it rather than inventing an error string.
$sent = $mail->send(
    $to,
    'QueuePilot mail test',
    "This is a test message from QueuePilot.\n\n"
    . "If you are reading this, SMTP is configured correctly and the welcome\n"
    . "links will reach your clinics.\n\n"
    . 'Sent: ' . gmdate('Y-m-d H:i:s') . " UTC\n"
);

if ($sent) {
    echo "Sent. Check the inbox — and the spam folder, which is where a new\n";
    echo "Gmail sender lands until the domain has some history.\n";
    exit(0);
}

if ($transport === 'log') {
    echo "Written to storage/logs/, not delivered (MAIL_TRANSPORT=log).\n";
    exit(1);
}
if ($transport === 'none') {
    echo "Not sent: MAIL_TRANSPORT is not set, so mail is only logged.\n";
    echo "Set MAIL_TRANSPORT=smtp (see docs/RUNBOOK.md section 2) to send for real.\n";
    exit(1);
}
echo "Not sent. The SMTP conversation is in storage/logs/app.log — search for\n";
echo "'mail.smtp' to see exactly which line the server rejected.\n";
exit(1);
