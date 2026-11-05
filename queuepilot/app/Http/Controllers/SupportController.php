<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Services\AuditService;

/**
 * Customer support.
 *
 * A clinic raises a ticket; the platform team answers it. Two audiences, one
 * table, and the split is enforced by role rather than by two controllers —
 * a clinic can only ever see its own tickets, and only a super admin can
 * change the status of somebody else's.
 *
 * There is deliberately no email notification on a new ticket yet: the
 * super admin dashboard shows the open count, and inventing a mail path that
 * silently fails would be worse than a visible queue.
 */
final class SupportController
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

        view('support/index', [
            'title' => 'Support',
            'tickets' => Db::all(
                'SELECT t.*, u.name AS opened_by,
                        (SELECT COUNT(*) FROM support_replies r WHERE r.ticket_id = t.id) AS reply_count
                   FROM support_tickets t
                   LEFT JOIN users u ON u.id = t.user_id
                  WHERE t.clinic_id = ?
                  ORDER BY CASE WHEN t.status IN (\'open\',\'waiting\') THEN 0 ELSE 1 END, t.updated_at DESC',
                [$clinicId]
            ),
            'canManage' => Auth::can('support.manage'),
            'active' => 'support',
        ]);
    }

    public function store(): void
    {
        $clinicId = $this->clinicId();
        $subject = trim(Request::string('subject'));
        $body = trim(Request::string('body'));
        $category = Request::string('category', 'general');
        $priority = Request::string('priority', 'normal');

        if (mb_strlen($subject) < 4) {
            flash('error', 'Give the ticket a subject of at least four characters.');
            redirect('/support');
            return;
        }
        if (mb_strlen($body) < 10) {
            flash('error', 'Describe the problem in a sentence or two so support can act on it.');
            redirect('/support');
            return;
        }

        $allowedCategories = ['general', 'billing', 'queue', 'technical', 'account'];
        $allowedPriorities = ['low', 'normal', 'high', 'urgent'];

        // Rate limited: a bug in a client that retries a form post should not
        // create forty identical tickets.
        $limit = \App\Core\RateLimiter::hit('support:' . $this->clinicId(), 10, 3600);
        if (!$limit['allowed']) {
            flash('error', 'Too many tickets in the last hour. Add to an existing one instead.');
            redirect('/support');
            return;
        }

        $id = Db::insert(
            'INSERT INTO support_tickets (clinic_id, user_id, subject, body, category, priority, status, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [
                $clinicId,
                Auth::id(),
                mb_substr($subject, 0, 200),
                mb_substr($body, 0, 5000),
                in_array($category, $allowedCategories, true) ? $category : 'general',
                in_array($priority, $allowedPriorities, true) ? $priority : 'normal',
                'open',
                Db::now(),
                Db::now(),
            ]
        );

        (new AuditService())->log($clinicId, 'support.ticket_opened', 'support_ticket', $id, ['subject' => $subject]);
        flash('success', 'Ticket #' . $id . ' opened. Support can see it now.');
        redirect('/support/' . $id);
    }

    public function show(string $id): void
    {
        $clinicId = $this->clinicId();
        $ticket = Db::one(
            'SELECT t.*, u.name AS opened_by FROM support_tickets t
               LEFT JOIN users u ON u.id = t.user_id
              WHERE t.id = ? AND t.clinic_id = ?',
            [(int) $id, $clinicId]
        );
        if (!$ticket) {
            abort(404, 'That ticket does not exist for this clinic.');
            return;
        }

        view('support/show', [
            'title' => 'Ticket #' . $ticket['id'],
            'ticket' => $ticket,
            'replies' => Db::all(
                'SELECT r.*, u.name AS author_name FROM support_replies r
                   LEFT JOIN users u ON u.id = r.user_id
                  WHERE r.ticket_id = ? ORDER BY r.created_at, r.id',
                [(int) $id]
            ),
            'active' => 'support',
        ]);
    }

    public function reply(string $id): void
    {
        $clinicId = $this->clinicId();
        $ticket = Db::one('SELECT * FROM support_tickets WHERE id = ? AND clinic_id = ?', [(int) $id, $clinicId]);
        if (!$ticket) {
            abort(404, 'That ticket does not exist for this clinic.');
            return;
        }
        if (in_array((string) $ticket['status'], ['resolved', 'closed'], true)) {
            flash('error', 'That ticket is ' . $ticket['status'] . '. Open a new one if the problem came back.');
            redirect('/support/' . (int) $id);
            return;
        }

        $body = trim(Request::string('body'));
        if ($body === '') {
            flash('error', 'Write something before replying.');
            redirect('/support/' . (int) $id);
            return;
        }

        Db::insert(
            'INSERT INTO support_replies (ticket_id, user_id, body, is_staff, created_at) VALUES (?,?,?,0,?)',
            [(int) $id, Auth::id(), mb_substr($body, 0, 5000), Db::now()]
        );
        Db::run("UPDATE support_tickets SET status = 'waiting', updated_at = ? WHERE id = ?", [Db::now(), (int) $id]);

        flash('success', 'Reply added.');
        redirect('/support/' . (int) $id);
    }
}
