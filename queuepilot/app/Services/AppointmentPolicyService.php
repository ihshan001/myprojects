<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Appointment policy — late grace, booking horizon and walk-in acceptance,
 * resolved per service rather than once per clinic.
 *
 * The clinic row already holds `late_grace_minutes` and the settings table
 * holds `booking.horizon_days`. Those are the clinic's default, and they stay
 * the default: this class only *overrides* them for a queue kind or a single
 * doctor, and anything left blank inherits. That ordering matters, because a
 * clinic that has never opened this screen must behave exactly as it did
 * before — no silent change to who gets seen.
 *
 * Resolution order, most specific first:
 *
 *   1. a policy for this doctor on this kind
 *   2. a policy for this kind
 *   3. the clinic's own defaults
 *
 * The result says which one answered, so a message to a patient can explain
 * itself and a support call can be settled by reading a row.
 */
final class AppointmentPolicyService
{
    public const KINDS = [
        'consultation' => 'Consultation',
        'lab'          => 'Laboratory',
        'pharmacy'     => 'Pharmacy',
        'general'      => 'General',
    ];

    /**
     * Every policy row for a clinic, with the names the screen needs.
     */
    public function all(int $clinicId, bool $activeOnly = true): array
    {
        $sql = 'SELECT p.*, d.name AS doctor_name
                  FROM appointment_policies p
                  LEFT JOIN doctors d ON d.id = p.doctor_id
                 WHERE p.clinic_id = ?';
        if ($activeOnly) {
            $sql .= ' AND p.is_active = 1';
        }
        $sql .= ' ORDER BY p.kind ASC, p.doctor_id IS NULL DESC, p.id ASC';

        return Db::all($sql, [$clinicId]);
    }

    /** Validate a posted policy. Returns human-readable problems. */
    public function validate(array $in): array
    {
        $errors = [];
        $kind = (string) ($in['kind'] ?? '');
        if (!isset(self::KINDS[$kind])) {
            $errors[] = 'Choose the service this policy is for.';
        }

        $grace = $this->nullableInt($in['late_grace_minutes'] ?? null);
        if (($in['late_grace_minutes'] ?? '') !== '' && ($grace === null || $grace < 0 || $grace > 240)) {
            $errors[] = 'Late grace is between 0 and 240 minutes, or blank to inherit the clinic default.';
        }

        $horizon = $this->nullableInt($in['horizon_days'] ?? null);
        if (($in['horizon_days'] ?? '') !== '' && ($horizon === null || $horizon < 1 || $horizon > 365)) {
            $errors[] = 'The booking horizon is between 1 and 365 days, or blank to inherit.';
        }

        if ($grace === null && $horizon === null && !array_key_exists('allow_walkins', $in)) {
            // Nothing to say. A row that overrides nothing is a comment.
            $errors[] = 'Set at least one of late grace, booking horizon, or walk-in acceptance — a policy that changes nothing is not a policy.';
        }

        return $errors;
    }

    /**
     * Create or replace the policy for this kind (and optional doctor).
     *
     * A submitted form always carries a walk-in choice, so the only way
     * `allow_walkins` is NULL is a doctor row saved by a caller that meant to
     * leave it alone. Storing NULL there is what lets the kind row keep
     * deciding, instead of a doctor's horizon override accidentally
     * re-opening a service that had closed itself to walk-ins.
     */
    public function save(int $clinicId, array $in, ?int $actorId = null): int
    {
        $errors = $this->validate($in);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        $kind = (string) $in['kind'];
        $doctorId = $this->nullableInt($in['doctor_id'] ?? null);
        if ($doctorId !== null) {
            if (Db::scalar('SELECT id FROM doctors WHERE id = ? AND clinic_id = ?', [$doctorId, $clinicId]) === null) {
                throw new \InvalidArgumentException('That doctor does not belong to this clinic.');
            }
        }

        $existing = $doctorId === null
            ? Db::one('SELECT id FROM appointment_policies WHERE clinic_id = ? AND kind = ? AND doctor_id IS NULL', [$clinicId, $kind])
            : Db::one('SELECT id FROM appointment_policies WHERE clinic_id = ? AND kind = ? AND doctor_id = ?', [$clinicId, $kind, $doctorId]);

        $row = [
            $this->nullableInt($in['late_grace_minutes'] ?? null),
            $this->nullableInt($in['horizon_days'] ?? null),
            array_key_exists('allow_walkins', $in)
                ? ((string) $in['allow_walkins'] === '0' ? 0 : 1)
                : null,
            Db::now(),
        ];

        if ($existing !== null) {
            Db::run(
                'UPDATE appointment_policies SET late_grace_minutes = ?, horizon_days = ?, allow_walkins = ?, is_active = 1, updated_at = ?
                  WHERE id = ? AND clinic_id = ?',
                [...$row, (int) $existing['id'], $clinicId]
            );
            return (int) $existing['id'];
        }

        return Db::insert(
            'INSERT INTO appointment_policies
               (late_grace_minutes, horizon_days, allow_walkins, updated_at, clinic_id, kind, doctor_id, is_active, created_at)
             VALUES (?,?,?,?,?,?,?,1,?)',
            [...$row, $clinicId, $kind, $doctorId, Db::now()]
        );
    }

    public function delete(int $clinicId, int $policyId): bool
    {
        if (Db::scalar('SELECT id FROM appointment_policies WHERE id = ? AND clinic_id = ?', [$policyId, $clinicId]) === null) {
            return false;
        }
        Db::run('DELETE FROM appointment_policies WHERE id = ? AND clinic_id = ?', [$policyId, $clinicId]);
        return true;
    }

    /**
     * The effective policy for one kind (and optionally one doctor).
     *
     * @return array{late_grace_minutes:int,horizon_days:int,allow_walkins:bool,source:string}
     *               `source` is 'doctor', 'kind' or 'clinic'.
     */
    public function resolve(int $clinicId, ?string $kind, ?int $doctorId = null): array
    {
        $clinic = Db::one('SELECT late_grace_minutes FROM clinics WHERE id = ?', [$clinicId]) ?? [];
        $resolved = [
            'late_grace_minutes' => max(0, (int) ($clinic['late_grace_minutes'] ?? 15)),
            'horizon_days'       => (new SlotService())->horizonDays($clinicId),
            'allow_walkins'      => true,
            'source'             => 'clinic',
        ];

        if ($kind === null || !isset(self::KINDS[$kind])) {
            return $resolved;
        }

        // Kind-wide first, then a doctor-specific row on top of it. Reading
        // them in that order means a doctor row that only sets the horizon
        // still inherits the kind's grace, which is usually what was meant.
        $rows = [];
        $rows[] = Db::one(
            'SELECT * FROM appointment_policies WHERE clinic_id = ? AND kind = ? AND doctor_id IS NULL AND is_active = 1',
            [$clinicId, $kind]
        );
        if ($doctorId !== null) {
            $rows[] = Db::one(
                'SELECT * FROM appointment_policies WHERE clinic_id = ? AND kind = ? AND doctor_id = ? AND is_active = 1',
                [$clinicId, $kind, $doctorId]
            );
        }

        $sources = ['kind', 'doctor'];
        foreach ($rows as $i => $row) {
            if ($row === null) {
                continue;
            }
            if ($row['late_grace_minutes'] !== null) {
                $resolved['late_grace_minutes'] = max(0, (int) $row['late_grace_minutes']);
            }
            if ($row['horizon_days'] !== null) {
                $resolved['horizon_days'] = max(1, min(365, (int) $row['horizon_days']));
            }
            if ($row['allow_walkins'] !== null) {
                $resolved['allow_walkins'] = (int) $row['allow_walkins'] === 1;
            }
            $resolved['source'] = $sources[$i];
        }

        return $resolved;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_INT) === false ? null : (int) $value;
    }
}
