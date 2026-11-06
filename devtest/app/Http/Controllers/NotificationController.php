<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Services\StaffNotificationService;

/**
 * Notification history.
 *
 * Two lists on one page, because "why did this patient not get their SMS" and
 * "what did the system tell the staff" are both asked at the same desk and
 * from different tables. Showing only one of them is how a clinic ends up
 * telling a patient the system "must have" messaged them.
 *
 * The patient list is masked: `to_number` holds only the last three digits
 * (see NotificationService::mask), so this screen can be left open at a
 * reception desk without exposing the phone book.
 */
final class NotificationController
{
    private function clinicId(): int
    {
        $id = Auth::clinicId();
        if ($id === null) {
            abort(403, 'Not attached to a clinic.');
        }
        return $id;
    }

    public function index(): void
    {
        $clinicId = $this->clinicId();
        $userId = (int) Auth::id();
        $service = new StaffNotificationService();

        $tab = Request::string('tab', 'staff');

        view('notifications/index', [
            'title' => 'Notifications',
            'tab' => $tab === 'patients' ? 'patients' : 'staff',
            'staffNotices' => $service->inbox($clinicId, $userId, 100),
            'unread' => $service->unreadCount($clinicId, $userId),
            'patientNotices' => Db::all(
                "SELECT n.id, n.channel, n.template, n.to_number, n.status, n.attempts,
                        n.last_error, n.scheduled_at, n.sent_at, n.created_at,
                        e.token_label, p.full_name
                   FROM notifications n
                   LEFT JOIN queue_entries e ON e.id = n.entry_id
                   LEFT JOIN patients p ON p.id = e.patient_id
                  WHERE n.clinic_id = ?
                  ORDER BY n.id DESC LIMIT 100",
                [$clinicId]
            ),
            'stats' => (new \App\Services\NotificationService())->stats($clinicId),
            'active' => 'notifications',
        ]);
    }

    public function markRead(string $id): void
    {
        $clinicId = $this->clinicId();
        $ok = (new StaffNotificationService())->markRead($clinicId, (int) Auth::id(), (int) $id);

        // A notice from another clinic simply does not exist here; saying so
        // is more useful than a silent redirect that looks like success.
        flash($ok ? 'success' : 'error', $ok ? 'Marked as read.' : 'That notice does not exist for this clinic.');
        redirect('/notifications');
    }

    public function markAllRead(): void
    {
        $clinicId = $this->clinicId();
        (new StaffNotificationService())->markAllRead($clinicId, (int) Auth::id());
        flash('success', 'All notices marked as read.');
        redirect('/notifications');
    }
}
