<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Log;

/**
 * Account suspension, closure and data retention.
 *
 * Three separate things that are easy to conflate and expensive to get wrong:
 *
 *   * **Suspension** — a platform decision (unpaid, abuse, request). The
 *     clinic can still sign in and read its data, but the queue engine
 *     refuses to issue tokens and the public surfaces go dark. At no point
 *     does a patient standing at the desk lose their place because of a
 *     billing dispute.
 *   * **Closure** — the clinic's own decision. Signs out all staff, hides
 *     every public surface, and starts a retention clock.
 *   * **Purge** — destructive and irreversible, so it is never automatic
 *     from a web request: `tools/purge.php` runs it, after the retention
 *     window, and it writes what it removed.
 *
 * The point of separating them is that a clinic that is 20 days late on an
 * invoice must not lose seven years of visit history, and a clinic that asks
 * to close must be able to change its mind during the retention window.
 */
final class LifecycleService
{
    /** Default retention before a closed clinic's data may be purged. */
    public const DEFAULT_RETENTION_DAYS = 90;

    // ------------------------------------------------------------------
    // Approval — the step before a clinic exists at all
    // ------------------------------------------------------------------
    //
    // Registration used to grant access outright, which meant anybody who
    // could reach /register could put real patients into a queue. These three
    // decisions are what stands between a stranger and a working clinic:
    //
    //   * **approve** — a signed-off clinic. Billing starts on the next
    //     invoice run, so the clinic is usable immediately.
    //   * **grantTrial** — the platform's decision to let a clinic run free
    //     until a date. This is the *only* way a clinic becomes a trial;
    //     signup no longer presumes one.
    //   * **decline** — a recorded refusal. The row stays, so the same email
    //     cannot register again in a loop, and the reason is answerable later.
    //
    // Approval is deliberately not idempotent-blind: approving an approved
    // clinic is a no-op with an explanation rather than a second audit entry,
    // because a double-click should not look like two decisions.

    /** Statuses that may still be decided on. */
    private const DECIDABLE = ['pending'];

    /** @return array{ok: bool, error?: string} */
    public function approve(int $clinicId, ?int $actorId = null, ?int $trialDays = null): array
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if (!$clinic) {
            return ['ok' => false, 'error' => 'That clinic does not exist.'];
        }
        if ($clinic['billing_status'] === 'active') {
            return ['ok' => false, 'error' => 'That clinic is already approved.'];
        }
        if ($clinic['declined_at'] !== null) {
            return ['ok' => false, 'error' => 'That clinic was declined. Reinstate it instead of approving.'];
        }
        if ($trialDays !== null && $trialDays > 0) {
            return $this->grantTrial($clinicId, $trialDays, $actorId);
        }

        // Billing is monthly in advance, so the first period starts today and
        // the renewal date is one cycle out. There is no arrears period for a
        // clinic that has never used the system.
        $cycle = (string) ($clinic['billing_cycle'] ?? 'monthly');
        $renewsOn = $this->cycleEnd(date('Y-m-d'), $cycle);

        Db::run(
            "UPDATE clinics SET billing_status = 'active', suspended_at = NULL, suspension_reason = NULL,
                    approved_at = ?, approved_by = ?, declined_at = NULL, decision_reason = NULL,
                    grace_until = NULL, renews_on = ?, updated_at = ?
              WHERE id = ?",
            [Db::now(), $actorId, $renewsOn, Db::now(), $clinicId]
        );

        // Approval without addresses leaves the clinic unusable: the owner has
        // nothing to open. Minting them here means the email that follows can
        // contain both URLs, and re-running approval cannot mint a second one.
        $addresses = (new ProvisionService())->provision(
            Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]) ?? $clinic
        );

        (new AuditService())->log($clinicId, 'platform.clinic_approved', 'clinic', $clinicId, [
            'actor' => $actorId,
            'site_host' => $addresses['site_host'],
            'ops_host' => $addresses['ops_host'],
        ]);
        Log::info('clinic.approved', ['clinic' => $clinicId, 'actor' => $actorId]);

        return ['ok' => true] + $addresses;
    }

    /**
     * Let a clinic run free until a date. Admin-granted, never self-serve.
     *
     * @return array{ok: bool, error?: string}
     */
    public function grantTrial(int $clinicId, int $days, ?int $actorId = null): array
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if (!$clinic) {
            return ['ok' => false, 'error' => 'That clinic does not exist.'];
        }
        if ($clinic['declined_at'] !== null) {
            return ['ok' => false, 'error' => 'That clinic was declined. Reinstate it instead.'];
        }
        $days = max(1, min(90, $days));
        $endsOn = date('Y-m-d', strtotime("+{$days} days"));

        Db::run(
            "UPDATE clinics SET billing_status = 'trial', trial_ends_on = ?, approved_at = ?,
                    approved_by = ?, suspended_at = NULL, suspension_reason = NULL,
                    grace_until = NULL, renews_on = NULL, updated_at = ?
              WHERE id = ?",
            [$endsOn, Db::now(), $actorId, Db::now(), $clinicId]
        );

        // A trial the owner cannot open is not a trial. Same addresses as a
        // paid clinic; only the billing state differs.
        $addresses = (new ProvisionService())->provision(
            Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]) ?? $clinic
        );

        (new AuditService())->log($clinicId, 'platform.clinic_trial_granted', 'clinic', $clinicId, [
            'days' => $days, 'ends_on' => $endsOn, 'actor' => $actorId,
        ]);
        Log::info('clinic.trial_granted', ['clinic' => $clinicId, 'days' => $days, 'actor' => $actorId]);

        return ['ok' => true, 'trial_ends_on' => $endsOn] + $addresses;
    }

    /** @return array{ok: bool, error?: string} */
    public function decline(int $clinicId, string $reason, ?int $actorId = null): array
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if (!$clinic) {
            return ['ok' => false, 'error' => 'That clinic does not exist.'];
        }
        if (!in_array($clinic['billing_status'], self::DECIDABLE, true)) {
            return ['ok' => false, 'error' => 'Only a clinic awaiting approval can be declined.'];
        }
        if (mb_strlen(trim($reason)) < 5) {
            return ['ok' => false, 'error' => 'Record why — the clinic sees this, and support will be asked.'];
        }

        Db::run(
            "UPDATE clinics SET billing_status = 'declined', declined_at = ?, decision_reason = ?,
                    is_active = 1, updated_at = ?
              WHERE id = ?",
            [Db::now(), trim($reason), Db::now(), $clinicId]
        );

        (new AuditService())->log($clinicId, 'platform.clinic_declined', 'clinic', $clinicId, [
            'reason' => trim($reason), 'actor' => $actorId,
        ]);
        Log::warn('clinic.declined', ['clinic' => $clinicId, 'actor' => $actorId]);

        return ['ok' => true];
    }

    /**
     * Clinics awaiting a decision, oldest first.
     *
     * Oldest first on purpose: the queue is worked from the top and a clinic
     * that signed up eleven days ago should not sit behind today's arrivals.
     *
     * @return list<array<string,mixed>>
     */
    public function awaitingDecision(): array
    {
        return Db::all(
            "SELECT c.*, u.name AS owner_name, u.email AS owner_email,
                    CAST(julianday('now') - julianday(c.created_at) AS INTEGER) AS days_waiting
               FROM clinics c
               LEFT JOIN users u ON u.clinic_id = c.id AND u.role = 'clinic_admin'
              WHERE c.billing_status = 'pending'
              ORDER BY c.created_at ASC"
        );
    }

    /**
     * Close the trial loop: a clinic whose trial date has passed loses access.
     *
     * Until this existed, `trial_ends_on` was written at signup and never
     * compared to anything, so a clinic that never paid kept the queue engine
     * indefinitely. Moving to 'suspended' rather than a new status is
     * deliberate — it reuses the gate that is already tested, keeps the data
     * untouched, and puts the clinic on the billing page where they can pay.
     *
     * @return list<array{id:int,name:string,trial_ends_on:string}>
     */
    public function expireTrials(?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $due = Db::all(
            "SELECT id, name, trial_ends_on FROM clinics
              WHERE billing_status = 'trial'
                AND trial_ends_on IS NOT NULL AND trial_ends_on <> ''
                AND substr(trial_ends_on, 1, 10) < ?
                AND closed_at IS NULL",
            [$today]
        );

        foreach ($due as $clinic) {
            $id = (int) $clinic['id'];
            Db::run(
                "UPDATE clinics SET billing_status = 'suspended', suspended_at = ?, updated_at = ?
                  WHERE id = ?",
                [Db::now(), Db::now(), $id]
            );
            (new AuditService())->log($id, 'platform.trial_expired', 'clinic', $id, [
                'trial_ends_on' => $clinic['trial_ends_on'],
            ]);
            Log::info('clinic.trial_expired', ['clinic' => $id, 'trial_ends_on' => $clinic['trial_ends_on']]);
        }

        return $due;
    }

    // ------------------------------------------------------------------
    // Dunning — the five-day warning before suspension
    // ------------------------------------------------------------------
    //
    // The rule the business actually runs on: a clinic that has not paid is
    // warned for five days, then suspended until the money arrives. Three
    // things make this harder than "if overdue, suspend":
    //
    //   * The clock must not depend on the cron running. If the nightly job
    //     misses a day, a clinic should not gain a day of free service, and it
    //     must not be suspended a day early either. So the deadline is stored
    //     as a date (`grace_until`), computed once when the invoice falls due.
    //   * Paying must clear the warning immediately, not at the next run. A
    //     clinic that transfers money at 6pm and still sees a red banner at
    //     9pm learns to distrust every other message the system shows them.
    //   * Suspension is the last step, and it must be reversible by the same
    //     payment that caused it — which `activate` already does.

    /** Days a clinic keeps working after an invoice falls due. */
    public const GRACE_DAYS = 5;

    /**
     * Start the warning clock for a clinic whose invoice has fallen due.
     *
     * Only ever moves a clinic *into* grace, never out of it: calling this on a
     * clinic already in grace must not extend the deadline, or a job that runs
     * every hour would grant an indefinite warning period.
     *
     * @return array{ok:bool, error?:string, grace_until?:string}
     */
    public function beginGrace(int $clinicId, ?string $from = null): array
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if (!$clinic) {
            return ['ok' => false, 'error' => 'That clinic does not exist.'];
        }
        if ($clinic['grace_until'] !== null) {
            return ['ok' => false, 'error' => 'That clinic is already in its warning period.'];
        }
        if ($clinic['billing_status'] !== 'active') {
            return ['ok' => false, 'error' => 'Only an active clinic can fall into arrears.'];
        }

        $until = date('Y-m-d', strtotime(($from ?? date('Y-m-d')) . ' +' . self::GRACE_DAYS . ' days'));
        Db::run('UPDATE clinics SET grace_until = ?, updated_at = ? WHERE id = ?',
            [$until, Db::now(), $clinicId]);

        (new AuditService())->log($clinicId, 'billing.grace_started', 'clinic', $clinicId, [
            'until' => $until, 'days' => self::GRACE_DAYS,
        ]);
        Log::info('clinic.grace_started', ['clinic' => $clinicId, 'until' => $until]);

        // Tell them, with the date. Starting a clock in the database and not
        // saying so is how a working clinic gets switched off by surprise.
        try {
            (new InvoiceMailer())->paymentDue($clinicId);
        } catch (\Throwable $e) {
            Log::warn('billing.payment_due_email_failed', ['clinic' => $clinicId, 'error' => $e->getMessage()]);
        }

        return ['ok' => true, 'grace_until' => $until];
    }

    /**
     * Clear the warning clock. Called whenever money lands.
     *
     * @return array{ok:bool, was_in_grace:bool}
     */
    public function clearGrace(int $clinicId): array
    {
        $inGrace = Db::scalar('SELECT grace_until FROM clinics WHERE id = ?', [$clinicId]) !== null;
        if ($inGrace) {
            Db::run('UPDATE clinics SET grace_until = NULL, updated_at = ? WHERE id = ?',
                [Db::now(), $clinicId]);
            (new AuditService())->log($clinicId, 'billing.grace_cleared', 'clinic', $clinicId);
        }
        return ['ok' => true, 'was_in_grace' => $inGrace];
    }

    /**
     * Suspend every clinic whose warning period has run out.
     *
     * Suspends rather than deletes: data is untouched, the clinic lands on the
     * billing page, and paying reinstates it. That is the same gate the rest of
     * the platform already uses, so a dunning suspension behaves exactly like a
     * manual one — one code path, one set of tests.
     *
     * @return list<array{id:int,name:string,grace_until:string}>
     */
    public function suspendOverdue(?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $due = Db::all(
            "SELECT id, name, grace_until FROM clinics
              WHERE billing_status = 'active'
                AND grace_until IS NOT NULL
                AND substr(grace_until, 1, 10) < ?
                AND closed_at IS NULL",
            [$today]
        );

        foreach ($due as $clinic) {
            $id = (int) $clinic['id'];
            Db::run(
                "UPDATE clinics SET billing_status = 'suspended', suspended_at = ?,
                        suspension_reason = ?, updated_at = ?
                  WHERE id = ?",
                [Db::now(), 'Payment overdue. Your account resumes as soon as payment is confirmed.',
                 Db::now(), $id]
            );
            (new AuditService())->log($id, 'billing.suspended_unpaid', 'clinic', $id, [
                'grace_until' => $clinic['grace_until'],
            ]);
            Log::warn('clinic.suspended_unpaid', ['clinic' => $id]);
        }

        return $due;
    }

    /** End of a billing cycle starting on $from: one month or twelve. */
    public function cycleEnd(string $from, string $cycle = 'monthly'): string
    {
        return $cycle === 'yearly'
            ? date('Y-m-d', strtotime($from . ' +1 year'))
            : date('Y-m-d', strtotime($from . ' +1 month'));
    }

    // ------------------------------------------------------------------
    // Suspension
    // ------------------------------------------------------------------

    /** @return array{ok: bool, error?: string} */
    public function suspend(int $clinicId, string $reason, ?int $actorId = null): array
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if (!$clinic) {
            return ['ok' => false, 'error' => 'That clinic does not exist.'];
        }
        if ($clinic['suspended_at'] !== null) {
            return ['ok' => false, 'error' => 'That clinic is already suspended.'];
        }
        if (mb_strlen(trim($reason)) < 5) {
            return ['ok' => false, 'error' => 'Record why — the clinic sees this and support will ask.'];
        }

        Db::run(
            "UPDATE clinics SET suspended_at = ?, suspension_reason = ?, billing_status = 'suspended', updated_at = ?
              WHERE id = ?",
            [Db::now(), mb_substr(trim($reason), 0, 255), Db::now(), $clinicId]
        );

        (new StaffNotificationService())->operational(
            $clinicId,
            'Account suspended',
            $reason . ' Patients cannot be issued tokens until this is resolved. Nothing has been deleted.',
            'error',
            '/billing'
        );

        (new AuditService())->log($clinicId, 'clinic.suspended', 'clinic', $clinicId, [
            'reason' => $reason, 'actor' => $actorId,
        ]);
        Log::warn('clinic.suspended', ['clinic' => $clinicId, 'reason' => $reason]);
        return ['ok' => true];
    }

    /** @return array{ok: bool, error?: string} */
    public function reinstate(int $clinicId, ?int $actorId = null): array
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if (!$clinic) {
            return ['ok' => false, 'error' => 'That clinic does not exist.'];
        }
        if ($clinic['suspended_at'] === null) {
            return ['ok' => false, 'error' => 'That clinic is not suspended.'];
        }

        // grace_until must be cleared too. Leaving a stale deadline behind
        // means the next dunning run suspends the clinic again for an invoice
        // that has just been paid — the worst possible message to send to
        // somebody who paid this morning.
        Db::run(
            "UPDATE clinics SET suspended_at = NULL, suspension_reason = NULL, grace_until = NULL,
                    billing_status = 'active', updated_at = ?
              WHERE id = ?",
            [Db::now(), $clinicId]
        );
        (new AuditService())->log($clinicId, 'clinic.reinstated', 'clinic', $clinicId, ['actor' => $actorId]);
        return ['ok' => true];
    }

    public function isSuspended(int $clinicId): bool
    {
        return Db::scalar('SELECT suspended_at FROM clinics WHERE id = ? AND suspended_at IS NOT NULL', [$clinicId]) !== null;
    }

    // ------------------------------------------------------------------
    // Closure
    // ------------------------------------------------------------------

    /**
     * The clinic asks to close. Not immediate: a retention window starts so
     * a mistaken click, or a change of mind, is recoverable.
     *
     * @return array{ok: bool, purge_after?: string, error?: string}
     */
    public function requestClosure(int $clinicId, int $retentionDays = self::DEFAULT_RETENTION_DAYS): array
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if (!$clinic) {
            return ['ok' => false, 'error' => 'That clinic does not exist.'];
        }
        if ($clinic['closure_requested_at'] !== null) {
            return ['ok' => false, 'error' => 'Closure has already been requested.'];
        }

        $retention = max(7, min(365, $retentionDays));
        $purgeAfter = date('Y-m-d H:i:s', time() + $retention * 86400);

        Db::transaction(function () use ($clinicId, $purgeAfter): void {
            Db::run(
                "UPDATE clinics SET closure_requested_at = ?, purge_after = ?, is_active = 0,
                        billing_status = 'closed', updated_at = ? WHERE id = ?",
                [Db::now(), $purgeAfter, Db::now(), $clinicId]
            );

            // Staff are signed out by deactivating their accounts: Auth::user()
            // filters on is_active, so this is an immediate and total cut.
            Db::run('UPDATE users SET is_active = 0 WHERE clinic_id = ?', [$clinicId]);

            // Open sessions must not be left 'open' — a display left running
            // in a closed clinic would keep showing a stale board.
            Db::run("UPDATE queues SET status = 'closed' WHERE clinic_id = ? AND status <> 'closed'", [$clinicId]);
        });

        (new AuditService())->log($clinicId, 'clinic.closure_requested', 'clinic', $clinicId, [
            'purge_after' => $purgeAfter,
        ]);
        Log::warn('clinic.closure_requested', ['clinic' => $clinicId, 'purge_after' => $purgeAfter]);

        return ['ok' => true, 'purge_after' => $purgeAfter];
    }

    /** @return array{ok: bool, error?: string} */
    public function cancelClosure(int $clinicId, ?int $actorId = null): array
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if (!$clinic || $clinic['closure_requested_at'] === null) {
            return ['ok' => false, 'error' => 'No closure request to cancel.'];
        }
        if ($clinic['closed_at'] !== null) {
            return ['ok' => false, 'error' => 'That clinic is already closed and purged data cannot be restored.'];
        }

        Db::transaction(function () use ($clinicId, $clinic): void {
            Db::run(
                "UPDATE clinics SET closure_requested_at = NULL, purge_after = NULL, is_active = 1,
                        billing_status = ?, updated_at = ? WHERE id = ?",
                [$clinic['suspended_at'] !== null ? 'suspended' : 'active', Db::now(), $clinicId]
            );
            // Staff accounts come back only for those still employed: the
            // users table has no separate "deactivated by closure" marker, so
            // this restores admins and doctors alike. Deliberate: a clinic
            // that cancels closure wants its team back.
            Db::run("UPDATE users SET is_active = 1 WHERE clinic_id = ? AND role IN ('clinic_admin','doctor','receptionist')", [$clinicId]);
        });

        (new AuditService())->log($clinicId, 'clinic.closure_cancelled', 'clinic', $clinicId, ['actor' => $actorId]);
        return ['ok' => true];
    }

    /**
     * Clinics whose retention window has expired and may be purged.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * Clinics whose retention window has ended.
     *
     * These are NOT authorised for deletion. The owner's rule is that a closed
     * clinic is *contacted* — an email, then a phone call — and a person
     * decides whether the data goes. `purge_approved_at` is that decision
     * recorded, and only `approvedForPurge()` returns clinics that may actually
     * be deleted.
     *
     * The distinction is the whole point: a timer that deletes on its own is
     * one missed email away from destroying a clinic's records because nobody
     * was at their desk in month four.
     *
     * @return list<array<string,mixed>>
     */
    public function purgeable(?string $now = null): array
    {
        return Db::all(
            'SELECT id, name, slug, email, phone, closure_requested_at, purge_after, purge_approved_at
               FROM clinics
              WHERE closure_requested_at IS NOT NULL
                AND purge_after IS NOT NULL
                AND purge_after <= ?',
            [$now ?? Db::now()]
        );
    }

    /**
     * Clinics a human has agreed may be deleted.
     *
     * @return list<array<string,mixed>>
     */
    public function approvedForPurge(?string $now = null): array
    {
        return Db::all(
            'SELECT id, name, slug, closure_requested_at, purge_after, purge_approved_at, purge_approved_by
               FROM clinics
              WHERE closure_requested_at IS NOT NULL
                AND purge_after IS NOT NULL
                AND purge_after <= ?
                AND purge_approved_at IS NOT NULL',
            [$now ?? Db::now()]
        );
    }

    /**
     * Record that the clinic was contacted and a person decided to delete.
     *
     * Requires a note as well as an actor: "who decided and why" is the only
     * thing that makes an irreversible deletion reviewable afterwards.
     *
     * @return array{ok:bool, error?:string}
     */
    public function approvePurge(int $clinicId, string $note, ?int $actorId = null): array
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if (!$clinic) {
            return ['ok' => false, 'error' => 'That clinic does not exist.'];
        }
        if ($clinic['closure_requested_at'] === null) {
            return ['ok' => false, 'error' => 'That clinic has not requested closure.'];
        }
        if ($clinic['purge_after'] === null || strtotime((string) $clinic['purge_after']) > time()) {
            return ['ok' => false, 'error' => 'The retention window has not ended yet.'];
        }
        if (mb_strlen(trim($note)) < 10) {
            return ['ok' => false, 'error' => 'Record how they were contacted and what they said.'];
        }

        Db::run(
            'UPDATE clinics SET purge_approved_at = ?, purge_approved_by = ?, updated_at = ? WHERE id = ?',
            [Db::now(), $actorId, Db::now(), $clinicId]
        );
        (new AuditService())->log($clinicId, 'clinic.purge_approved', 'clinic', $clinicId, [
            'note' => trim($note), 'actor' => $actorId,
        ]);
        Log::warn('clinic.purge_approved', ['clinic' => $clinicId, 'actor' => $actorId]);

        return ['ok' => true];
    }

    /** Withdraw a deletion decision. Available until the purge actually runs. */
    public function revokePurgeApproval(int $clinicId, ?int $actorId = null): array
    {
        Db::run('UPDATE clinics SET purge_approved_at = NULL, purge_approved_by = NULL, updated_at = ? WHERE id = ?',
            [Db::now(), $clinicId]);
        (new AuditService())->log($clinicId, 'clinic.purge_revoked', 'clinic', $clinicId, ['actor' => $actorId]);
        return ['ok' => true];
    }

    /**
     * Irreversible. Deletes the clinic's rows in dependency order inside one
     * transaction, then marks the clinic row closed rather than deleting it —
     * the slug must never be reusable by somebody else while an invoice
     * history with that name may exist in the platform's own accounting.
     *
     * @return array{ok: bool, removed?: array<string, int>, error?: string}
     */
    public function purge(int $clinicId): array
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if (!$clinic) {
            return ['ok' => false, 'error' => 'That clinic does not exist.'];
        }
        if ($clinic['purge_after'] === null || strtotime((string) $clinic['purge_after']) > time()) {
            return ['ok' => false, 'error' => 'The retention window has not expired for that clinic.'];
        }

        // Tables with a direct clinic_id, in dependency order (children first).
        // `support_replies` is deliberately absent: it hangs off ticket_id, not
        // clinic_id, and is removed through its parent below.
        $tables = [
            'queue_events', 'queue_entries', 'appointments', 'notifications',
            'staff_notifications', 'doctor_schedules', 'doctor_leave', 'holidays',
            'departments', 'queues', 'doctors', 'rooms', 'specialties', 'patients',
        ];

        $removed = [];
        Db::transaction(function () use ($clinicId, $tables, &$removed, $clinic): void {
            foreach ($tables as $table) {
                $removed[$table] = (int) Db::scalar('SELECT COUNT(*) FROM ' . $table . ' WHERE clinic_id = ?', [$clinicId]);
                Db::run('DELETE FROM ' . $table . ' WHERE clinic_id = ?', [$clinicId]);
            }

            // Support threads: replies first, then the tickets themselves.
            $ticketIds = array_column(Db::all('SELECT id FROM support_tickets WHERE clinic_id = ?', [$clinicId]), 'id');
            foreach ($ticketIds as $tid) {
                Db::run('DELETE FROM support_replies WHERE ticket_id = ?', [(int) $tid]);
            }
            $removed['support_replies'] = count($ticketIds);
            $removed['support_tickets'] = (int) Db::scalar('SELECT COUNT(*) FROM support_tickets WHERE clinic_id = ?', [$clinicId]);
            Db::run('DELETE FROM support_tickets WHERE clinic_id = ?', [$clinicId]);

            // Rows keyed by user rather than by clinic.
            $userIds = Db::all('SELECT id FROM users WHERE clinic_id = ?', [$clinicId]);
            foreach ($userIds as $u) {
                Db::run('DELETE FROM password_resets WHERE user_id = ?', [(int) $u['id']]);
            }
            Db::run('DELETE FROM staff_invites WHERE clinic_id = ?', [$clinicId]);
            // login_attempts keys on email, not user id — it records attempts
            // for addresses that never resolved to a user.
            foreach ($userIds as $u) {
                $email = Db::scalar('SELECT email FROM users WHERE id = ?', [(int) $u['id']]);
                if ($email !== null) {
                    Db::run('DELETE FROM login_attempts WHERE email = ?', [mb_strtolower((string) $email)]);
                }
            }

            Db::run('DELETE FROM users WHERE clinic_id = ?', [$clinicId]);
            $removed['users'] = count($userIds);

            Db::run(
                "UPDATE clinics SET closed_at = ?, is_active = 0, name = ?, email = NULL, phone = NULL,
                        address_line = NULL, city = NULL, updated_at = ? WHERE id = ?",
                [Db::now(), '[closed] ' . $clinic['name'], Db::now(), $clinicId]
            );
        });

        // Invoices are kept: they are the platform's financial record, and a
        // clinic closing does not erase what it owed or paid.
        (new AuditService())->log($clinicId, 'clinic.purged', 'clinic', $clinicId, $removed);
        Log::warn('clinic.purged', ['clinic' => $clinicId, 'removed' => $removed]);
        return ['ok' => true, 'removed' => $removed];
    }

    /** Retention state for the clinic's own settings page. */
    public function status(int $clinicId): array
    {
        $row = Db::one(
            'SELECT suspended_at, suspension_reason, closure_requested_at, closed_at, purge_after
               FROM clinics WHERE id = ?',
            [$clinicId]
        ) ?? [];
        return [
            'suspended' => ($row['suspended_at'] ?? null) !== null,
            'suspension_reason' => $row['suspension_reason'] ?? null,
            'closure_requested' => ($row['closure_requested_at'] ?? null) !== null,
            'purge_after' => $row['purge_after'] ?? null,
            'closed' => ($row['closed_at'] ?? null) !== null,
        ];
    }
}
