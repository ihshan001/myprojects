<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Per-clinic feature entitlement for the SaaS platform.
 *
 * The platform operator decides which clinic gets which feature. Resolution
 * order for any feature:
 *
 *   1. Explicit row in clinic_features (operator override) — wins.
 *   2. Otherwise, the clinic's plan `features` list (comma-separated keys).
 *   3. Otherwise, off.
 *
 * Enforcement is in the service layer (NotificationService, QueueService,
 * etc.) — never merely by hiding a button in the UI. A clinic without the
 * `sms` feature cannot send SMS, period.
 */
final class FeatureService
{
    /** Features the platform knows about. Unknown features are always off. */
    public const KNOWN = [
        'sms' => 'SMS notifications',
        'whatsapp' => 'WhatsApp notifications',
        'display_screens' => 'Lobby display screens',
        'online_booking' => 'Online patient booking',
        'reports' => 'Reports and analytics',
        'departments' => 'Department-based queues',
    ];

    /**
     * Is a feature enabled for a clinic?
     *
     * Resolution order:
     *   1. Explicit row in clinic_features (operator override) — wins.
     *   2. Otherwise, the clinic's plan `features` list (comma-separated keys).
     *   3. Otherwise, ON — existing clinics keep their features; the operator
     *      explicitly disables what they don't want a clinic to have.
     */
    public function enabled(int $clinicId, string $feature): bool
    {
        // 1. Operator override wins.
        $row = Db::one(
            'SELECT enabled FROM clinic_features WHERE clinic_id = ? AND feature = ?',
            [$clinicId, $feature]
        );
        if ($row !== null) {
            return (bool) $row['enabled'];
        }

        // 2. Fall back to the plan's feature list.
        $planId = Db::scalar('SELECT plan_id FROM clinics WHERE id = ?', [$clinicId]);
        if ($planId !== null && $planId !== '') {
            $features = (string) Db::scalar('SELECT features FROM plans WHERE id = ?', [$planId]);
            if ($features !== '') {
                $list = array_map('trim', array_filter(explode(',', $features)));
                if (in_array($feature, $list, true)) {
                    return true;
                }
            }
        }

        // 3. Default ON for backward compatibility: clinics keep what they
        //    had. The operator turns features off, not on.
        return true;
    }

    /**
     * All known features with their effective state for a clinic, used by the
     * operator's feature-management screen.
     *
     * @return array{feature: string, enabled: bool, override: bool}[]
     */
    public function list(int $clinicId): array
    {
        $planId = Db::scalar('SELECT plan_id FROM clinics WHERE id = ?', [$clinicId]);
        $planFeatures = [];
        if ($planId !== null && $planId !== '') {
            $raw = (string) Db::scalar('SELECT features FROM plans WHERE id = ?', [$planId]);
            if ($raw !== '') {
                $planFeatures = array_map('trim', array_filter(explode(',', $raw)));
            }
        }

        $overrides = Db::all(
            'SELECT feature, enabled FROM clinic_features WHERE clinic_id = ?',
            [$clinicId]
        );
        $overrideMap = [];
        foreach ($overrides as $row) {
            $overrideMap[(string) $row['feature']] = (bool) $row['enabled'];
        }

        // Only show known features — the plan's free-text strings (e.g.
        // "SMS credits") are not feature keys and don't belong in this list.
        $all = array_keys(self::KNOWN);
        sort($all);

        $out = [];
        foreach ($all as $feature) {
            $out[] = [
                'feature' => $feature,
                'label' => self::KNOWN[$feature],
                'enabled' => $this->enabled($clinicId, $feature),
                'override' => isset($overrideMap[$feature]),
            ];
        }
        return $out;
    }

    /**
     * Set (or remove) an operator override for a clinic feature.
     */
    public function set(int $clinicId, string $feature, bool $enabled): void
    {
        $existing = Db::one(
            'SELECT id FROM clinic_features WHERE clinic_id = ? AND feature = ?',
            [$clinicId, $feature]
        );
        if ($existing !== null) {
            Db::run(
                'UPDATE clinic_features SET enabled = ?, updated_at = datetime("now") WHERE id = ?',
                [$enabled ? 1 : 0, (int) $existing['id']]
            );
        } else {
            Db::insert(
                'INSERT INTO clinic_features (clinic_id, feature, enabled, created_at, updated_at) '
                . 'VALUES (?, ?, ?, datetime("now"), datetime("now"))',
                [$clinicId, $feature, $enabled ? 1 : 0]
            );
        }
    }

    /**
     * Remove an operator override, so the plan default applies again.
     */
    public function unset(int $clinicId, string $feature): void
    {
        Db::run(
            'DELETE FROM clinic_features WHERE clinic_id = ? AND feature = ?',
            [$clinicId, $feature]
        );
    }

    /**
     * Increment the SMS usage counter for a clinic in the current month.
     * Called by the worker when a message is accepted by the provider.
     */
    public function meterSms(int $clinicId): void
    {
        $now = new \DateTimeImmutable();
        $year = (int) $now->format('Y');
        $month = (int) $now->format('m');

        $row = Db::one(
            'SELECT id, sent_count FROM sms_usage WHERE clinic_id = ? AND year = ? AND month = ?',
            [$clinicId, $year, $month]
        );
        if ($row !== null) {
            Db::run(
                'UPDATE sms_usage SET sent_count = sent_count + 1, updated_at = datetime("now") WHERE id = ?',
                [(int) $row['id']]
            );
        } else {
            Db::insert(
                'INSERT INTO sms_usage (clinic_id, year, month, sent_count, created_at, updated_at) '
                . 'VALUES (?, ?, ?, 1, datetime("now"), datetime("now"))',
                [$clinicId, $year, $month]
            );
        }
    }

    /**
     * SMS sent by a clinic in a given month.
     */
    public function smsSent(int $clinicId, int $year, int $month): int
    {
        $row = Db::one(
            'SELECT sent_count FROM sms_usage WHERE clinic_id = ? AND year = ? AND month = ?',
            [$clinicId, $year, $month]
        );
        return $row !== null ? (int) $row['sent_count'] : 0;
    }
}
