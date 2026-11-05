<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;

/** Append-only audit trail. Never updated, never deleted by the app. */
final class AuditService
{
    public function log(?int $clinicId, string $action, ?string $entity = null, ?int $entityId = null, array $meta = []): void
    {
        try {
            Db::insert(
                'INSERT INTO audit_logs (clinic_id, actor_id, action, entity, entity_id, ip, meta_json, created_at)
                 VALUES (?,?,?,?,?,?,?,?)',
                [
                    $clinicId, Auth::id(), $action, $entity, $entityId,
                    Request::ip(), $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, Db::now(),
                ]
            );
        } catch (\Throwable $e) {
            // an audit failure must never take down a clinical workflow
            \App\Core\Log::error('audit.write.failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    public function forClinic(int $clinicId, int $limit = 100, ?string $action = null): array
    {
        $sql = 'SELECT a.*, u.name AS actor_name FROM audit_logs a
                  LEFT JOIN users u ON u.id = a.actor_id
                 WHERE a.clinic_id = ?';
        $params = [$clinicId];
        if ($action) {
            $sql .= ' AND a.action = ?';
            $params[] = $action;
        }
        $sql .= ' ORDER BY a.id DESC LIMIT ' . max(1, min(500, $limit));
        return Db::all($sql, $params);
    }
}
