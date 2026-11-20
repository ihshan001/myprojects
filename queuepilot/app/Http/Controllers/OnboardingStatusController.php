<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;

/**
 * "Your clinic is with us" — the page a clinic can reach before it is approved.
 *
 * Signup used to hand out a working session, so there was no such page because
 * there was nothing to wait for. Now a new clinic lands here, and this is the
 * only clinic-side page a pending or declined clinic can open.
 *
 * It exists for a specific reason: the failure mode of an approval queue is a
 * nervous owner refreshing a page that tells them nothing, then emailing
 * support to ask whether their signup worked. Telling them plainly — received,
 * what happens next, how long it usually takes, and who to ask — removes that
 * message before it is written.
 *
 * It is deliberately reachable while signed in and deliberately contains no
 * clinic data: nothing here should leak to an unapproved account.
 */
final class OnboardingStatusController
{
    public function index(): void
    {
        $clinic = Auth::clinic();
        if ($clinic === null) {
            redirect('/login');
            return;
        }

        $status = (string) ($clinic['billing_status'] ?? 'active');
        $owner  = Db::one(
            "SELECT name, email FROM users WHERE clinic_id = ? AND role = 'clinic_admin' ORDER BY id LIMIT 1",
            [(int) $clinic['id']]
        );

        view('onboarding/status', [
            'title'  => 'Your clinic application',
            'clinic' => $clinic,
            'status' => $status,
            'owner'  => $owner,
            'reason' => $clinic['decision_reason'] ?? null,
            'trialEndsOn' => $clinic['trial_ends_on'] ?? null,
        ]);
    }
}
