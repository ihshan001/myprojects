<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Services\OnboardingService;

/**
 * Getting started.
 *
 * Registration leaves a clinic with a working workspace and no statement of
 * what is still missing. The steps below are measured from the database, not
 * from a checklist the owner ticked, so this page cannot claim a clinic is
 * ready when it is not — and cannot nag about work that is already done.
 */
final class OnboardingController
{
    public function __construct(private readonly OnboardingService $onboarding = new OnboardingService()) {}

    private function clinicId(): int
    {
        $id = Auth::clinicId();
        if ($id === null) {
            abort(403, 'Onboarding belongs to a clinic.');
        }
        return $id;
    }

    public function index(): void
    {
        $clinicId = $this->clinicId();

        view('onboarding/index', [
            'title'   => 'Getting started',
            'steps'   => $this->onboarding->steps($clinicId),
            'summary' => $this->onboarding->summary($clinicId),
            'clinic'  => Auth::clinic(),
        ]);
    }

    /** The owner has looked at the display; that step is now done. */
    public function seenDisplay(): void
    {
        $clinicId = $this->clinicId();
        $this->onboarding->noteSeen($clinicId, 'display_seen');
        redirect('/display');
    }
}
