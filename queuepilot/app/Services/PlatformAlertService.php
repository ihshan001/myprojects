<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Env;
use App\Core\Log;

/**
 * Tell the platform that a clinic is waiting.
 *
 * The decision queue at /admin/clinics was the only signal that somebody had
 * applied, and it is a page you have to remember to open. A clinic that applies
 * on Friday and is still pending on Tuesday has not been declined — it has been
 * missed. The whole approval gate is worth nothing if the gatekeeper is not told
 * there is somebody at it.
 *
 * Two channels, because they fail differently:
 *
 *   email  reaches somebody who is not looking at the admin console. Sent to
 *          COMPANY_ALERTS_EMAIL. Honest about failure — MAIL_TRANSPORT=none is
 *          a supported install, and an operator needs to know the alert never
 *          left so they can check the queue by hand.
 *   bell   an in-app row per platform admin, so the alert survives an inbox.
 *
 * Deliberately not a webhook or an SMS: neither can be configured on shared
 * hosting without a provider account, and a "notification" that depends on
 * infrastructure nobody has set up is a notification that does not happen.
 */
final class PlatformAlertService
{
    /**
     * A new clinic has applied and is waiting for a decision.
     *
     * @return array{email_sent:bool, admins_notified:int}
     */
    public function clinicApplied(array $clinic, ?string $planName = null): array
    {
        $name  = (string) ($clinic['name'] ?? 'A clinic');
        $owner = (string) ($clinic['owner_name'] ?? '');
        $email = (string) ($clinic['email'] ?? '');
        $phone = (string) ($clinic['phone'] ?? '');
        $city  = (string) ($clinic['city'] ?? '');
        $slug  = (string) ($clinic['slug'] ?? '');
        $plan  = $planName !== null && $planName !== '' ? $planName : 'a plan';

        $title = 'New clinic waiting: ' . $name;
        $lines = [
            "{$name} has applied for {$plan} and is waiting for a decision.",
            '',
            '  Owner:  ' . ($owner !== '' ? $owner : '—'),
            '  Email:  ' . ($email !== '' ? $email : '—'),
            '  Phone:  ' . ($phone !== '' ? $phone : '—'),
            '  City:   ' . ($city !== '' ? $city : '—'),
            '  Slug:   ' . ($slug !== '' ? $slug : '—'),
            '',
            'Nothing is live yet: the clinic cannot issue a token until somebody',
            'approves it or grants a trial.',
            '',
            'Review it here:',
            '  ' . $this->adminUrl('/admin/clinics'),
            '',
            'What happens next is a decision only a person can make:',
            '  · a 14-day trial  — no invoice, and the clinic gets both URLs',
            '  · a paid plan     — activate it, and the invoice follows',
            '  · decline         — with a reason they can read',
            '',
            '— ' . $this->productName(),
        ];

        $notified = $this->notifyAdmins($title, "{$name} · {$email} · applied for {$plan}", 'pending', '/admin/clinics');

        $sent = $this->emailPlatform($title, implode("\n", $lines));
        Log::info('platform.clinic_applied', [
            'clinic' => (int) ($clinic['id'] ?? 0),
            'email_sent' => $sent,
            'admins_notified' => $notified,
        ]);

        return ['email_sent' => $sent, 'admins_notified' => $notified];
    }

    /**
     * A clinic has claimed it paid. Somebody has to check the bank.
     *
     * @return array{email_sent:bool, admins_notified:int}
     */
    public function paymentClaimed(int $clinicId, int $invoiceId): array
    {
        $clinic  = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        $invoice = Db::one('SELECT * FROM invoices WHERE id = ? AND clinic_id = ?', [$invoiceId, $clinicId]);
        if ($clinic === null || $invoice === null) {
            return ['email_sent' => false, 'admins_notified' => 0];
        }

        $title = 'Payment claimed: ' . $clinic['name'];
        $body  = "{$clinic['name']} has recorded a transfer against invoice {$invoice['number']}.\n\n"
            . '  Amount:     ' . money((float) $invoice['amount']) . "\n"
            . '  Reference:  ' . ((string) ($invoice['reference'] ?? '') ?: '—') . "\n"
            . '  Period:     ' . $invoice['period_from'] . ' to ' . $invoice['period_to'] . "\n\n"
            . "Check the bank and verify or reject it here:\n  " . $this->adminUrl('/admin/billing') . "\n\n"
            . "Verifying settles the invoice, extends the subscription and emails a receipt.\n"
            . "Rejecting leaves the invoice unpaid and tells them why.\n\n"
            . '— ' . $this->productName();

        $notified = $this->notifyAdmins($title, $clinic['name'] . ' · ' . $invoice['number'] . ' · ' . money((float) $invoice['amount']), 'warning', '/admin/billing');
        $sent = $this->emailPlatform($title, $body);

        return ['email_sent' => $sent, 'admins_notified' => $notified];
    }

    /* ------------------------------------------------------------- internals */

    /**
     * One in-app row per platform admin.
     *
     * Written to `platform_notifications`, not `staff_notifications`: the
     * latter has a NOT NULL foreign key to `clinics(id)`, and a platform admin
     * belongs to no clinic. The first attempt used clinic_id 0 and hit that
     * constraint — which is the constraint doing its job, because filing "a
     * clinic applied" under some other tenant's id would have shown it in that
     * clinic's bell.
     */
    private function notifyAdmins(string $title, string $body, string $kind, string $link): int
    {
        $admins = Db::all("SELECT id FROM users WHERE role = 'super_admin' AND is_active = 1");
        $count = 0;
        foreach ($admins as $admin) {
            try {
                Db::insert(
                    'INSERT INTO platform_notifications (user_id, kind, title, body, link, created_at)
                     VALUES (?,?,?,?,?,?)',
                    [(int) $admin['id'], $kind, mb_substr($title, 0, 200), mb_substr($body, 0, 500), $link, Db::now()]
                );
                $count++;
            } catch (\Throwable $e) {
                Log::warn('platform.alert_bell_failed', ['admin' => (int) $admin['id'], 'error' => $e->getMessage()]);
            }
        }
        return $count;
    }

    /**
     * Unread platform notices for one admin, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function unread(int $userId, int $limit = 20): array
    {
        return Db::all(
            'SELECT * FROM platform_notifications
              WHERE read_at IS NULL AND (user_id = ? OR user_id IS NULL)
              ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)),
            [$userId]
        );
    }

    public function markRead(int $id, int $userId): void
    {
        Db::run(
            'UPDATE platform_notifications SET read_at = ? WHERE id = ? AND (user_id = ? OR user_id IS NULL)',
            [Db::now(), $id, $userId]
        );
    }

    /**
     * Email the platform. Reports whether it actually left.
     *
     * Falls back to the first super admin's address when no alerts address is
     * configured, so a fresh install with only the seeded admin still gets the
     * message rather than silently swallowing it.
     */
    private function emailPlatform(string $subject, string $body): bool
    {
        $to = trim((string) Env::get('company.alerts_email', ''));

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $to = (string) (Db::scalar(
                "SELECT email FROM users WHERE role = 'super_admin' AND is_active = 1 ORDER BY id LIMIT 1"
            ) ?: '');
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Log::warn('platform.alert_no_address', ['subject' => $subject]);
            return false;
        }

        try {
            return (new MailService())->send($to, $subject, $body);
        } catch (\Throwable $e) {
            Log::warn('platform.alert_email_failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function adminUrl(string $path): string
    {
        return \App\Core\Tenant::platformUrl($path);
    }

    private function productName(): string
    {
        return (string) Env::get('app.name', 'QueuePilot');
    }
}
