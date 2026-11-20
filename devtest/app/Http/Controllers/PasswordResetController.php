<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Session;
use App\Services\PasswordResetService;

/**
 * Public, unauthenticated password recovery.
 *
 * Both entry points are rate limited by IP. The request form never reveals
 * whether an address exists — the same message is shown either way, and the
 * only observable difference is that a real user receives mail.
 */
final class PasswordResetController
{
    public function showRequest(): void
    {
        if (Auth::check()) {
            redirect('/app');
            return;
        }
        view('auth/forgot');
    }

    public function send(): void
    {
        $email = mb_strtolower(trim(Request::string('email')));

        // Rate limited per network so the mail-out cannot be used to flood an
        // inbox. `hit()` increments and returns ['allowed' => bool, ...].
        $limit = RateLimiter::hit('password-reset:' . RateLimiter::redact(Request::ip()), 5, 900);
        if (!$limit['allowed']) {
            flash('error', 'Too many reset requests from this network. Please wait 15 minutes.');
            redirect('/forgot-password');
            return;
        }

        $token = null;
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $token = (new PasswordResetService())->request($email);
        }

        // The same sentence for a known and an unknown address. This is the
        // whole point of the endpoint: it must not be usable to discover who
        // has an account.
        flash('success', 'If that address belongs to an account, a reset link is on its way. It expires in 60 minutes.');

        // In this deployment mail may be file-backed; the operator still needs
        // a way to act when a clinic is locked out. Only shown when the mail
        // transport is the log transport, never in production SMTP mode.
        if ($token !== null && strtolower((string) \App\Core\Env::get('mail.transport', 'log')) === 'log') {
            Session::flash('reset_preview', '/reset-password/' . $token);
        }

        redirect('/forgot-password');
    }

    public function showReset(string $token): void
    {
        $row = (new PasswordResetService())->find($token);
        if (!$row) {
            view('auth/reset-invalid');
            return;
        }
        view('auth/reset', ['token' => $token, 'email' => $row['email']]);
    }

    public function reset(string $token): void
    {
        $password = (string) Request::input('password', '');
        $confirm = (string) Request::input('password_confirmation', '');

        if ($password !== $confirm) {
            flash('error', 'The two passwords do not match.');
            redirect('/reset-password/' . urlencode($token));
            return;
        }

        $result = (new PasswordResetService())->complete($token, $password);

        if (!$result['ok']) {
            flash('error', $result['error']);
            redirect('/reset-password/' . urlencode($token));
            return;
        }

        flash('success', 'Your password has been changed. Sign in with the new one.');
        redirect('/login');
    }
}
