<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Departments: the operational unit between a clinic and its queues.
 *
 * A *specialty* answers "what does this doctor practise". A *department*
 * answers "which part of the building does this patient walk to". They are
 * not the same question, which is why adding department-based queues,
 * displays and reports needed a new table rather than a rename.
 *
 * Everything here is clinic-scoped by argument, and every method takes the
 * clinic id from the caller rather than reading ambient state — so a stale
 * controller cannot accidentally operate across tenants.
 */
final class DepartmentService
{
    /** @return array<int, array<string, mixed>> */
    public function all(int $clinicId, bool $activeOnly = false): array
    {
        $sql = 'SELECT d.*, doc.name AS lead_name,
                       (SELECT COUNT(*) FROM queues q WHERE q.department_id = d.id) AS queue_count,
                       (SELECT COUNT(*) FROM doctors x WHERE x.department_id = d.id) AS doctor_count
                  FROM departments d
                  LEFT JOIN doctors doc ON doc.id = d.lead_doctor_id
                 WHERE d.clinic_id = ?';
        if ($activeOnly) {
            $sql .= ' AND d.is_active = 1';
        }
        $sql .= ' ORDER BY d.sort_order, d.name';
        return Db::all($sql, [$clinicId]);
    }

    public function find(int $clinicId, int $id): ?array
    {
        return Db::one('SELECT * FROM departments WHERE id = ? AND clinic_id = ?', [$id, $clinicId]);
    }

    /** @return array{ok: bool, id?: int, error?: string} */
    public function create(int $clinicId, array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2) {
            return ['ok' => false, 'error' => 'Give the department a name of at least two characters.'];
        }
        $exists = Db::scalar(
            'SELECT id FROM departments WHERE clinic_id = ? AND lower(name) = lower(?)',
            [$clinicId, $name]
        );
        if ($exists !== null) {
            return ['ok' => false, 'error' => 'A department with that name already exists.'];
        }

        $lead = isset($in['lead_doctor_id']) && (int) $in['lead_doctor_id'] > 0 ? (int) $in['lead_doctor_id'] : null;
        if ($lead !== null && !Db::one('SELECT id FROM doctors WHERE id = ? AND clinic_id = ?', [$lead, $clinicId])) {
            // A lead from another clinic would leak a name into this clinic's
            // department list, so it is refused rather than silently nulled.
            return ['ok' => false, 'error' => 'That doctor does not belong to this clinic.'];
        }

        $id = Db::insert(
            'INSERT INTO departments (clinic_id, name, code, description, floor, lead_doctor_id, sort_order, is_active, created_at)
             VALUES (?,?,?,?,?,?,?,1,?)',
            [
                $clinicId,
                $name,
                mb_substr(trim((string) ($in['code'] ?? '')), 0, 20) ?: null,
                mb_substr(trim((string) ($in['description'] ?? '')), 0, 500) ?: null,
                mb_substr(trim((string) ($in['floor'] ?? '')), 0, 60) ?: null,
                $lead,
                max(0, (int) ($in['sort_order'] ?? 0)),
                Db::now(),
            ]
        );

        (new AuditService())->log($clinicId, 'department.created', 'department', $id, ['name' => $name]);
        return ['ok' => true, 'id' => $id];
    }

    /** @return array{ok: bool, error?: string} */
    public function update(int $clinicId, int $id, array $in): array
    {
        $dept = $this->find($clinicId, $id);
        if (!$dept) {
            return ['ok' => false, 'error' => 'That department does not belong to this clinic.'];
        }

        $name = trim((string) ($in['name'] ?? $dept['name']));
        if (mb_strlen($name) < 2) {
            return ['ok' => false, 'error' => 'Give the department a name of at least two characters.'];
        }
        $clash = Db::scalar(
            'SELECT id FROM departments WHERE clinic_id = ? AND lower(name) = lower(?) AND id <> ?',
            [$clinicId, $name, $id]
        );
        if ($clash !== null) {
            return ['ok' => false, 'error' => 'Another department already has that name.'];
        }

        $lead = isset($in['lead_doctor_id']) && (int) $in['lead_doctor_id'] > 0 ? (int) $in['lead_doctor_id'] : null;
        if ($lead !== null && !Db::one('SELECT id FROM doctors WHERE id = ? AND clinic_id = ?', [$lead, $clinicId])) {
            return ['ok' => false, 'error' => 'That doctor does not belong to this clinic.'];
        }

        Db::run(
            'UPDATE departments SET name = ?, code = ?, description = ?, floor = ?, lead_doctor_id = ?, sort_order = ?, is_active = ?
              WHERE id = ? AND clinic_id = ?',
            [
                $name,
                mb_substr(trim((string) ($in['code'] ?? '')), 0, 20) ?: null,
                mb_substr(trim((string) ($in['description'] ?? '')), 0, 500) ?: null,
                mb_substr(trim((string) ($in['floor'] ?? '')), 0, 60) ?: null,
                $lead,
                max(0, (int) ($in['sort_order'] ?? $dept['sort_order'])),
                isset($in['is_active']) ? (int) (bool) $in['is_active'] : (int) $dept['is_active'],
                $id,
                $clinicId,
            ]
        );

        (new AuditService())->log($clinicId, 'department.updated', 'department', $id, ['name' => $name]);
        return ['ok' => true];
    }

    /**
     * Deactivating is the only removal offered.
     *
     * Deleting would cascade `department_id` to NULL on queues and doctors,
     * silently flattening historical reports — "which department did this
     * patient go through" would be unanswerable for the past. Deactivating
     * hides it from new assignments and keeps history intact.
     */
    public function deactivate(int $clinicId, int $id): array
    {
        $dept = $this->find($clinicId, $id);
        if (!$dept) {
            return ['ok' => false, 'error' => 'That department does not belong to this clinic.'];
        }
        Db::run('UPDATE departments SET is_active = 0 WHERE id = ? AND clinic_id = ?', [$id, $clinicId]);
        (new AuditService())->log($clinicId, 'department.deactivated', 'department', $id, ['name' => $dept['name']]);
        return ['ok' => true];
    }

    /** Queues in a department, for the department board and display. */
    public function queues(int $clinicId, int $departmentId, ?string $date = null): array
    {
        return Db::all(
            'SELECT q.*, d.name AS doctor_name, r.name AS room_name
               FROM queues q
               LEFT JOIN doctors d ON d.id = q.doctor_id
               LEFT JOIN rooms r ON r.id = d.room_id
              WHERE q.clinic_id = ? AND q.department_id = ? AND q.session_date = ?
              ORDER BY q.name',
            [$clinicId, $departmentId, $date ?? Db::today()]
        );
    }
}
