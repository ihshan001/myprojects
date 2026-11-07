<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Domain\Queue\QueueRepository;
use App\Domain\Queue\QueueService;
use App\Domain\Queue\QueueStateMachine;
use App\Services\ReportService;

/**
 * Reception dashboard: today at a glance + every live queue in the clinic.
 * Designed to be readable in one screen at 1366×768 without scrolling for
 * the primary action (issuing a token).
 */
final class DashboardController
{
    /**
     * DEVTEST entry point, replacing the deleted landing page.
     *
     * Each port is pinned to one identity, so "/" means "the workspace this
     * role actually owns": the platform console for a super admin, the live
     * board for everybody else. No marketing page, no sign-in.
     */
    public function home(): void
    {
        redirect(Auth::isSuper() ? '/admin' : '/app');
    }

    public function index(): void
    {
        // A platform administrator has no clinic. Rendering the clinic
        // dashboard for them produced a 500: the view assumes there is at
        // least one queue. Send them to the console they actually own instead
        // of special-casing every clinic view for a role that never uses it.
        if (Auth::isSuper() && Auth::clinicId() === null) {
            redirect('/admin');
            return;
        }

        $clinicId = (int) Auth::clinicId();
        $clinic = Auth::clinic();
        $repo = new QueueRepository();
        $service = new QueueService();
        $reports = new ReportService();

        $role = Auth::role();
        $canOperate = Auth::can('queue.operate');
        $isDoctor = $role === 'doctor';

        // A doctor sees only their own queues and therefore only their own
        // patients. Reception and admins see the whole clinic.
        $ownDoctorId = null;
        if ($isDoctor) {
            $ownDoctorId = Auth::user()['doctor_id'] ?? null;
            $ownDoctorId = $ownDoctorId !== null ? (int) $ownDoctorId : 0; // 0 = matches nothing
        }

        $queues = $repo->liveQueues($clinicId, null, $ownDoctorId);

        if ($isDoctor) {
            $ownQueueIds = array_map(static fn (array $q): int => (int) $q['id'], $queues);
            $board = array_values(array_filter(
                $repo->clinicBoard($clinicId),
                static fn (array $row): bool => in_array((int) $row['queue_id'], $ownQueueIds, true)
            ));
        } else {
            $board = $repo->clinicBoard($clinicId);
        }
        $summary = $reports->daySummary($clinicId);

        // waiting per queue + "now serving" per queue
        $byQueue = [];
        foreach ($board as $row) {
            $byQueue[(int) $row['queue_id']][] = $row;
        }
        $serving = [];
        foreach ($queues as $q) {
            $serving[(int) $q['id']] = $service->nowServing($clinicId, (int) $q['id']);
        }

        view('dashboard/index', [
            'title' => 'Live board',
            'queues' => $queues,
            'board' => $board,
            'byQueue' => $byQueue,
            'serving' => $serving,
            'summary' => $summary,
            'canOperate' => $canOperate,
            'isDoctor' => $isDoctor,
            'doctors' => (new \App\Services\DoctorService())->all($clinicId),
            'displayToken' => (string) Db::scalar(
                "SELECT value FROM settings WHERE scope='clinic' AND owner_id=? AND key_name='display_token'",
                [$clinicId]
            ),
            'autoRefresh' => 30,
        ]);
    }
}
