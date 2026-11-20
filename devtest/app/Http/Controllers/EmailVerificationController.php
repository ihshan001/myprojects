<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Services\EmailVerificationService;

/**
 * Email confirmation, from both directions.
 *
 * Public: the link in the email. Signed in: the resend button on the billing
 * page, because a clinic that is blocked from issuing tokens needs somewhere
 * obvious to press, not an instruction to go and find an old email.
 */
final class EmailVerificationController
{
    /** The link in the email. No session needed — it may be opened on a phone. */
    public function verify(string $token): void
    {
        RateLimiter::enforce('verify:ip:' . Request::ip(), 20, 3600);

        $service = new EmailVerificationService();
        $userId = $service->verify($token);

        if ($userId === null) {
            view('auth/verify_result', [
                'title' => 'That link did not work',
                'ok' => false,
                'message' => 'This confirmation link has expired or has already been used. '
                    . 'Sign in and press "Resend confirmation" to get a new one.',
            ], false);
            return;
        }

        $user = Db::one('SELECT name, email, clinic_id FROM users WHERE id = ?', [$userId]);

        view('auth/verify_result', [
            'title' => 'Email confirmed',
            'ok' => true,
            'message' => 'Thank you — ' . ($user['email'] ?? 'the address') . ' is now confirmed. '
                . 'Your clinic can issue tokens to patients.',
            'signedIn' => Auth::check(),
        ], false);
    }

    /** Signed in: send (or re-send) the confirmation mail. */
    public function resend(): void
    {
        $userId = Auth::id();
        if ($userId === null) {
            redirect('/login');
            return;
        }

        RateLimiter::enforce('verify:resend:' . $userId, 3, 3600);

        $sent = (new EmailVerificationService())->send((int) $userId, true);
        if ($sent === null) {
            flash('info', 'This address is already confirmed.');
        } else {
            flash('success', 'Confirmation sent. Check the inbox for the address on this account.');
        }

        back('/billing');
    }

    /**
     * Platform override: vouch for an address by hand.
     *
     * Needed because mail delivery fails: a clinic on a bad mail server would
     * otherwise be unable to work at all, and "wait for your ISP" is not a
     * support answer. The action is audited so it is visible afterwards.
     */
    public function verifyByHand(string $id): void
    {
        if (!Auth::isSuper()) {
            abort(403, 'Only the platform team can verify an address by hand.');
        }

        $ok = (new EmailVerificationService())->verifyByHand((int) $id, (int) Auth::id());
        if (!$ok) {
            flash('error', 'That account does not exist.');
        } else {
            flash('success', 'Address marked verified. The clinic can issue tokens now.');
        }

        back('/admin/clinics');
    }
}
