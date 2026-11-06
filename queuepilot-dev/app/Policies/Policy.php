<?php
declare(strict_types=1);

namespace App\Policies;

use App\Core\Auth;

/**
 * Base policy for resource-level authorization.
 *
 * A policy answers not just "can this user do X?" but "can this user do X
 * on this specific resource?" — for example, can this receptionist edit
 * queue entry #442 at their own clinic, but not entry #999 at another clinic.
 *
 * Extend this class for each resource type (QueueEntry, Patient, Doctor, etc.)
 * and implement methods named after the action they guard.
 *
 * Example:
 *
 *   final class QueueEntryPolicy extends Policy
   {
       public function update(int $entryId): bool
       {
           $entry = Db::one('SELECT clinic_id FROM queue_entries WHERE id = ?', [$entryId]);
           return $entry !== null
               && (int) $entry['clinic_id'] === Auth::clinicId();
       }
     }
 */
abstract class Policy
{
    /** The authenticated user's clinic id, or null for platform users. */
    protected function clinicId(): ?int
    {
        return Auth::clinicId();
    }

    /** True when the user is a platform super administrator. */
    protected function isSuperAdmin(): bool
    {
        return Auth::isSuper();
    }

    /** True when the user is authenticated at all. */
    protected function authenticated(): bool
    {
        return Auth::check();
    }

    /** The authenticated user's role, or null. */
    protected function role(): ?string
    {
        return Auth::role();
    }
}
