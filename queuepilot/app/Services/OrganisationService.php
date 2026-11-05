<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Str;

/**
 * Organisations are a *reporting* grouping, not a tenancy boundary.
 *
 * This distinction is the whole design:
 *
 *   * A branch's data never moves. Every query in the application still
 *     filters by `clinic_id`, and every authorisation decision still comes
 *     from the signed-in user's own clinic. Joining an organisation cannot
 *     widen anyone's access to another branch's patients.
 *   * What an organisation adds is a read-only roll-up for the group's owner:
 *     how many patients each branch saw, how long they waited, which branch is
 *     behind. That view is only reachable by a `clinic_admin` of a clinic that
 *     belongs to the organisation — never by staff, never by a doctor.
 *   * Deleting or moving a clinic between organisations is an admin action and
 *     is audited.
 */
final class OrganisationService
{
    /** Organisation the current clinic belongs to, or null. */
    public function forClinic(int $clinicId): ?array
    {
        return Db::one(
            'SELECT o.* FROM organisations o
               JOIN clinics c ON c.organisation_id = o.id
              WHERE c.id = ?',
            [$clinicId]
        );
    }

    /** Branches of an organisation, each with today's activity. */
    public function branches(int $organisationId): array
    {
        $today = Db::today();
        return Db::all(
            "SELECT c.id, c.name, c.slug, c.city, c.is_active, c.billing_status, c.brand_color,
                    (SELECT COUNT(*) FROM queue_entries e
                      WHERE e.clinic_id = c.id AND e.joined_at >= ?) AS seen_today,
                    (SELECT COUNT(*) FROM queue_entries e
                      WHERE e.clinic_id = c.id AND e.status = 'waiting') AS waiting_now,
                    (SELECT COUNT(*) FROM queue_entries e
                      WHERE e.clinic_id = c.id AND e.status = 'in_consultation') AS in_room,
                    (SELECT ROUND(AVG(e.wait_minutes))
                       FROM queue_entries e
                      WHERE e.clinic_id = c.id AND e.wait_minutes IS NOT NULL
                        AND e.joined_at >= ?) AS avg_wait,
                    (SELECT COUNT(*) FROM users u
                      WHERE u.clinic_id = c.id AND u.is_active = 1) AS staff
               FROM clinics c
              WHERE c.organisation_id = ?
              ORDER BY c.name",
            [$today . ' 00:00:00', $today . ' 00:00:00', $organisationId]
        );
    }

    /** @return array<string, mixed> Aggregates across every branch. */
    public function rollup(int $organisationId): array
    {
        $branches = $this->branches($organisationId);
        $sum = static function (string $key) use ($branches): int {
            return array_sum(array_map(static fn (array $b): int => (int) ($b[$key] ?? 0), $branches));
        };
        $waits = array_values(array_filter(
            array_map(static fn (array $b): ?float => $b['avg_wait'] === null ? null : (float) $b['avg_wait'], $branches),
            static fn (?float $v): bool => $v !== null
        ));

        return [
            'branches'    => count($branches),
            'seen_today'  => $sum('seen_today'),
            'waiting_now' => $sum('waiting_now'),
            'in_room'     => $sum('in_room'),
            'avg_wait'    => $waits === [] ? null : (int) round(array_sum($waits) / count($waits)),
            'busiest'     => $this->busiest($branches),
        ];
    }

    private function busiest(array $branches): ?array
    {
        $best = null;
        foreach ($branches as $branch) {
            if ($best === null || (int) $branch['waiting_now'] > (int) $best['waiting_now']) {
                $best = $branch;
            }
        }
        return $best;
    }

    /* ------------------------------------------------------------------ */
    /* Admin actions                                                       */
    /* ------------------------------------------------------------------ */

    public function create(string $name, ?string $ownerEmail = null, ?string $notes = null): array
    {
        $name = trim($name);
        if (mb_strlen($name) < 2) {
            return ['ok' => false, 'error' => 'Enter an organisation name.'];
        }
        $slug = Str::slug($name);
        if ($slug === '' || Db::scalar('SELECT id FROM organisations WHERE slug = ?', [$slug]) !== null) {
            return ['ok' => false, 'error' => 'An organisation with a similar name already exists.'];
        }
        $id = Db::insert(
            'INSERT INTO organisations (name, slug, owner_email, notes, is_active, created_at, updated_at)
             VALUES (?,?,?,?,1,?,?)',
            [$name, $slug, $ownerEmail, $notes, Db::now(), Db::now()]
        );
        (new AuditService())->log(null, 'organisation.created', 'organisation', $id, ['name' => $name]);
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Attach a clinic to an organisation.
     *
     * Requires the acting user to be a super admin: this crosses the tenancy
     * boundary (it changes who can *see a summary of* that clinic), so it is a
     * platform decision rather than a clinic one.
     */
    public function attachClinic(int $organisationId, int $clinicId): array
    {
        $org = Db::one('SELECT * FROM organisations WHERE id = ?', [$organisationId]);
        $clinic = Db::one('SELECT id, name, organisation_id FROM clinics WHERE id = ?', [$clinicId]);
        if (!$org || !$clinic) {
            return ['ok' => false, 'error' => 'Organisation or clinic not found.'];
        }
        Db::run(
            'UPDATE clinics SET organisation_id = ?, updated_at = ? WHERE id = ?',
            [$organisationId, Db::now(), $clinicId]
        );
        (new AuditService())->log($clinicId, 'organisation.branch_attached', 'clinic', $clinicId, [
            'organisation_id' => $organisationId, 'from' => $clinic['organisation_id'],
        ]);
        return ['ok' => true];
    }

    public function detachClinic(int $clinicId): array
    {
        $clinic = Db::one('SELECT id, organisation_id FROM clinics WHERE id = ?', [$clinicId]);
        if (!$clinic) {
            return ['ok' => false, 'error' => 'Clinic not found.'];
        }
        Db::run('UPDATE clinics SET organisation_id = NULL, updated_at = ? WHERE id = ?', [Db::now(), $clinicId]);
        (new AuditService())->log($clinicId, 'organisation.branch_detached', 'clinic', $clinicId, [
            'was' => $clinic['organisation_id'],
        ]);
        return ['ok' => true];
    }

    public function all(): array
    {
        return Db::all(
            'SELECT o.*, (SELECT COUNT(*) FROM clinics c WHERE c.organisation_id = o.id) AS branches
               FROM organisations o ORDER BY o.name'
        );
    }
}
