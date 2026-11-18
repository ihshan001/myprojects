<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Services\WelcomeService;

/**
 * The page behind a welcome link.
 *
 * Public and unauthenticated: the owner is not signed in yet, and the token in
 * the URL is the credential. Everything here is written for somebody who has
 * just been handed a URL and does not yet know what the two addresses are for.
 */
final class WelcomeController
{
    public function show(string $token): void
    {
        $invite = $this->find($token);
        view('auth/welcome', [
            'title'  => 'Set your password',
            'token'  => $token,
            'invite' => $invite,
            'error'  => $invite === null ? $this->whyInvalid($token) : null,
            'clinic' => $invite === null ? null
                : Db::one('SELECT name FROM clinics WHERE id = ?', [(int) $invite['clinic_id']]),
        ], false);
    }

    public function accept(string $token): void
    {
        $invite = $this->find($token);
        if ($invite === null) {
            redirect('/welcome/' . rawurlencode($token));
            return;
        }

        $password = (string) Request::input('password', '');
        $confirm  = (string) Request::input('password_confirmation', '');

        if ($password !== $confirm) {
            flash('error', 'The two passwords do not match.');
            redirect('/welcome/' . rawurlencode($token));
            return;
        }

        $result = (new WelcomeService())->redeem($token, $password);
        if (!$result['ok']) {
            flash('error', (string) $result['error']);
            redirect('/welcome/' . rawurlencode($token));
            return;
        }

        // Sign them in immediately. Making somebody type the password they set
        // ten seconds ago, on the strength of a link that just proved they own
        // the mailbox, is ceremony without security.
        $user = Db::one('SELECT * FROM users WHERE id = ?', [(int) $result['user_id']]);
        if ($user !== null) {
            // completeLogin(), not a hand-rolled session write: it regenerates
            // the id, rotates CSRF, stamps started_at and resolves the clinic.
            // Setting user_id directly would leave all four undone, which is
            // exactly the kind of session that behaves oddly for a week.
            Auth::completeLogin($user, Request::ip());
        }

        flash('success', 'Your password is set. Welcome to QueuePilot.');
        redirect('/getting-started');
    }

    private function find(string $token): ?array
    {
        $invite = Db::one(
            'SELECT * FROM staff_invites WHERE token_hash = ? ORDER BY id DESC LIMIT 1',
            [hash('sha256', $token)]
        );
        if ($invite === null) {
            return null;
        }
        if ($invite['accepted_at'] !== null) {
            return null;
        }
        if (strtotime((string) $invite['expires_at']) < time()) {
            return null;
        }
        return $invite;
    }

    /** Say which of the three failures it was — "invalid link" alone generates a support call. */
    private function whyInvalid(string $token): string
    {
        $invite = Db::one(
            'SELECT accepted_at, expires_at FROM staff_invites WHERE token_hash = ? ORDER BY id DESC LIMIT 1',
            [hash('sha256', $token)]
        );
        if ($invite === null) {
            return 'This link is not one we issued. If you copied it from an email, check it was the whole address.';
        }
        if ($invite['accepted_at'] !== null) {
            return 'This link has already been used. Sign in with the password you chose.';
        }
        return 'This link has expired. Ask us and we will send a fresh one.';
    }
}
