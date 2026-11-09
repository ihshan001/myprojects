<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Domain\Queue\QueueRepository;
use App\Domain\Queue\QueueService;
use App\Domain\Queue\QueueStateMachine;
use App\Services\DoctorService;
use App\Services\ScheduleService;

/**
 * The doctor workspace.
 *
 * The PDF is unambiguous that this is a product in its own right, not a
 * filtered reception dashboard:
 *
 *   "The doctor interface must be even simpler than the receptionist
 *    interface. A doctor should not have to manage patient registration,
 *    clinic configuration, billing or reports to perform their normal work."
 *
 * So this controller resolves everything from the doctor's own record and
 * never offers a clinic-wide view. A doctor who is not linked to a doctor
 * profile (a misconfigured account) gets an explanatory screen rather than
 * an empty board they cannot act on.
 */
final class DoctorController
{
    /**
     * Resolve the doctor profile behind the signed-in account.
     *
     * Every method funnels through here so the "this account is not linked to
     * a doctor" case is handled once. Resource-level authorisation: we take
     * the doctor id from the session, never from the browser.
     */
    private function doctorId(): ?int
    {
        $id = Auth::user()['doctor_id'] ?? null;

        return $id !== null && (int) $id > 0 ? (int) $id : null;
    }

    /** Clinic id, or null for a platform admin with no clinic. */
    private function clinicId(): ?int
    {
        return Auth::clinicId();
    }

    /* ------------------------------------------------------------------ */
    /* Today's queue — the one screen a doctor actually works from        */
    /* ------------------------------------------------------------------ */

    public function today(): void
    {
        $clinicId = $this->clinicId();
        if ($clinicId === null) {
            redirect('/admin');
            return;
        }
        $doctorId = $this->doctorId();

        $repo    = new QueueRepository();
        $service = new QueueService();

        // A doctor is scoped to their own queue. Reception sees the clinic;
        // a doctor seeing five other doctors' waiting lists is noise.
        $queue = $doctorId !== null
            ? $repo->queueForDoctor($clinicId, $doctorId)
            : null;

        $nowServing  = $queue !== null ? $service->nowServing($clinicId, (int) $queue['id']) : null;
        $nextWaiting = $queue !== null
            ? $repo->nextWaitingForCall($clinicId, (int) $queue['id'])
            : null;
        $waiting     = $queue !== null ? $this->waitingCount($repo, $clinicId, (int) $queue['id']) : 0;

        view('doctor/today', [
            'title'       => 'Today’s queue',
            'doctorId'    => $doctorId,
            'doctor'      => $doctorId !== null
                ? (new DoctorService())->find($clinicId, $doctorId)
                : null,
            'queue'       => $queue,
            'session'     => $queue !== null ? $service->sessionState($queue) : null,
            'board'       => $queue !== null ? $repo->board($clinicId, (int) $queue['id']) : [],
            'nowServing'  => $nowServing,
            'nextWaiting' => $nextWaiting,
            'waiting'     => $waiting,
            'estimate'    => $queue !== null
                ? $service->estimate($queue, $clinicId, $waiting)
                : null,
            'autoRefresh' => 20,
        ]);
    }

    private function waitingCount(QueueRepository $repo, int $clinicId, int $queueId): int
    {
        return (int) Db::scalar(
            "SELECT COUNT(*) FROM queue_entries
              WHERE clinic_id = ? AND queue_id = ? AND status = 'waiting'",
            [$clinicId, $queueId]
        );
    }

    /* ------------------------------------------------------------------ */
    /* My schedule                                                        */
    /* ------------------------------------------------------------------ */

    public function schedule(): void
    {
        $clinicId = $this->clinicId();
        if ($clinicId === null) {
            redirect('/admin');
            return;
        }
        $doctorId = $this->doctorId();
        $doctors  = new DoctorService();
        $sched    = new ScheduleService();

        view('doctor/schedule', [
            'title'    => 'My schedule',
            'doctorId' => $doctorId,
            'doctor'   => $doctorId !== null ? $doctors->find($clinicId, $doctorId) : null,
            // Read-only. A doctor reviews their own hours here; changing them
            // is a clinic-owner decision and lives in the clinic calendar.
            'week'     => $doctorId !== null ? $sched->asWeek($clinicId, $doctorId) : [],
            'leave'    => $doctorId !== null ? $doctors->leave($clinicId) : [],
            'holidays' => Db::all(
                'SELECT * FROM holidays WHERE clinic_id = ? AND date >= ? ORDER BY date LIMIT 20',
                [$clinicId, Db::today()]
            ),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* My patients / visit history                                        */
    /* ------------------------------------------------------------------ */

    public function patients(): void
    {
        $clinicId = $this->clinicId();
        if ($clinicId === null) {
            redirect('/admin');
            return;
        }
        $doctorId = $this->doctorId();

        // PDF permission: "Manage patients — Doctor: own patients". Scoping
        // happens in the SQL, not by filtering afterwards, so there is no
        // page size at which another doctor's patient leaks through.
        $visits = $doctorId !== null
            ? Db::all(
                "SELECT e.id, e.token_label, e.status, e.joined_at, e.started_at, e.finished_at,
                        e.wait_minutes, e.consult_minutes, p.full_name, p.code AS patient_code, p.phone
                   FROM queue_entries e
                   JOIN patients p ON p.id = e.patient_id
                   JOIN queues q ON q.id = e.queue_id
                  WHERE e.clinic_id = ? AND q.doctor_id = ?
                  ORDER BY e.id DESC
                  LIMIT 200",
                [$clinicId, $doctorId]
            )
            : [];

        view('doctor/patients', [
            'title'  => 'My patients',
            'visits' => $visits,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* My profile                                                         */
    /* ------------------------------------------------------------------ */

    public function profile(): void
    {
        $clinicId = $this->clinicId();
        if ($clinicId === null) {
            redirect('/admin');
            return;
        }
        $doctorId = $this->doctorId();

        view('doctor/profile', [
            'title'  => 'My profile',
            'user'   => Auth::user(),
            'doctor' => $doctorId !== null
                ? (new DoctorService())->find($clinicId, $doctorId)
                : null,
        ]);
    }
}
