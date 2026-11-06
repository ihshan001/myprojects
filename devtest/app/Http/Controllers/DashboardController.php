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
        \App\Core\View::share('boardData', null);
        $data = $this->boardData();
        if ($data === null) return;
        view('dashboard/index', $data);
    }

    /**
     * Just the live-queue card, for the 5s refresh. Returning rendered HTML
     * rather than JSON means the poller cannot invent a second row template
     * that drifts away from the approved design.
     */
    public function panel(): void
    {
        $data = $this->boardData();
        if ($data === null) return;
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo \App\Core\View::partial('board-panel', $data);
    }

    /** @return array<string,mixed>|null null when it already redirected */
    private function boardData(): ?array
    {
        // A platform administrator has no clinic. Rendering the clinic
        // dashboard for them produced a 500: the view assumes there is at
        // least one queue. Send them to the console they actually own instead
        // of special-casing every clinic view for a role that never uses it.
        if (Auth::isSuper() && Auth::clinicId() === null) {
            redirect('/admin');
            return null;
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
        $displayToken = (string) Db::scalar(
            "SELECT value FROM settings WHERE scope='clinic' AND owner_id=? AND key_name='display_token'",
            [$clinicId]
        );

        // waiting per queue + "now serving" per queue
        $byQueue = [];
        foreach ($board as $row) {
            $byQueue[(int) $row['queue_id']][] = $row;
        }
        $serving = [];
        foreach ($queues as $q) {
            $serving[(int) $q['id']] = $service->nowServing($clinicId, (int) $q['id']);
        }

        // ---- Reference board: one queue is "on stage" at a time, chosen by
        // the doctor tabs. Everything below is scoped to that queue so the
        // spotlight cards and the waiting list always agree with each other.
        $requested = \App\Core\Request::int('queue');
        $activeQueue = null;
        foreach ($queues as $q) {
            if ((int) $q['id'] === $requested) { $activeQueue = $q; break; }
        }
        if ($activeQueue === null) {
            // Prefer the first open queue with someone in the room, then the
            // first open queue, then whatever exists.
            foreach ($queues as $q) {
                if ($q['status'] === 'open' && (int) $q['active_count'] > 0) { $activeQueue = $q; break; }
            }
            if ($activeQueue === null) {
                foreach ($queues as $q) {
                    if ($q['status'] === 'open') { $activeQueue = $q; break; }
                }
            }
            $activeQueue ??= $queues[0] ?? null;
        }

        $activeId = $activeQueue ? (int) $activeQueue['id'] : 0;
        $stageRows = $byQueue[$activeId] ?? [];
        $inRoom = null; $nextUp = null; $waiting = [];
        foreach ($stageRows as $row) {
            $st = (string) $row['status'];
            if ($inRoom === null && ($st === 'in_consultation' || $st === 'called')) { $inRoom = $row; continue; }
            if ($st === 'waiting') { $waiting[] = $row; }
        }
        if ($waiting !== []) { $nextUp = array_shift($waiting); }

        // Estimates come from the domain's own range estimator — never a
        // hand-rolled number. Only the rows we actually draw are costed.
        $estimates = [];
        if ($activeQueue) {
            $ahead = 0;
            foreach (($nextUp ? [$nextUp] : []) as $r) {
                $estimates[(int) $r['id']] = $service->estimate($activeQueue, $clinicId, $ahead);
            }
            foreach (array_slice($waiting, 0, 5) as $r) {
                $ahead++;
                $estimates[(int) $r['id']] = $service->estimate($activeQueue, $clinicId, $ahead);
            }
        }

        // Patient tracking: the clinic's public waiting-room board is what a
        // patient can open on their own phone without an account.
        $trackUrl = null; $trackQr = null;
        if ($displayToken !== '') {
            $trackUrl = rtrim((string) \App\Core\Env::get('app.url', ''), '/') . '/board/' . $displayToken;
            if ($trackUrl === '/board/' . $displayToken) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
                $trackUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/board/' . $displayToken;
            }
            $trackQr = \App\Support\QrCode::svg($trackUrl, 3, 2);
        }

        $longest = (int) round((float) ($summary['max_wait'] ?? 0));

        return [
            'title' => 'Live board',
            'queues' => $queues,
            'board' => $board,
            'byQueue' => $byQueue,
            'serving' => $serving,
            'summary' => $summary,
            'canOperate' => $canOperate,
            'isDoctor' => $isDoctor,
            'doctors' => (new \App\Services\DoctorService())->all($clinicId),
            'displayToken' => $displayToken,
            'autoRefresh' => 5,
            'activeQueue' => $activeQueue,
            'activeId' => $activeId,
            'inRoom' => $inRoom,
            'nextUp' => $nextUp,
            'waitingRows' => $waiting,
            'estimates' => $estimates,
            'trackUrl' => $trackUrl,
            'trackQr' => $trackQr,
            'longestWait' => $longest,
            'bellCount' => (new \App\Services\StaffNotificationService())->unreadCount($clinicId, (int) Auth::id()),
        ];
    }
}
