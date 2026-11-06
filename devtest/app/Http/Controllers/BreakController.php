<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Services\BreakService;

/**
 * Breaks: the planned ones and the ones that happened.
 *
 * Two screens' worth of behaviour lives here because they are two views of the
 * same fact. Starting and ending a break is an operational action any queue
 * operator performs; planning one for a weekday is a clinic-owner decision.
 */
final class BreakController
{
    private function clinicId(): int
    {
        $id = Auth::clinicId();
        if ($id === null) {
            abort(403, 'Breaks belong to a clinic.');
        }
        return $id;
    }

    public function index(): void
    {
        $clinicId = $this->clinicId();
        $service = new BreakService();

        $today = Db::today();
        $weekStart = date('Y-m-d', strtotime($today . ' -6 days'));

        view('breaks/index', [
            'title' => 'Breaks',
            'kinds' => BreakService::KINDS,
            'today' => $service->today($clinicId),
            'openQueues' => Db::all(
                "SELECT id, name, status, break_started_at, break_kind
                   FROM queues WHERE clinic_id = ? AND session_date = ? AND status <> 'closed'
                  ORDER BY name",
                [$clinicId, $today]
            ),
            'week' => $service->summary($clinicId, $weekStart, $today),
            'scheduled' => Db::all(
                'SELECT s.*, d.name AS doctor_name
                   FROM scheduled_breaks s LEFT JOIN doctors d ON d.id = s.doctor_id
                  WHERE s.clinic_id = ?
                  ORDER BY s.weekday, s.starts_at',
                [$clinicId]
            ),
            'doctors' => Db::all(
                'SELECT id, name FROM doctors WHERE clinic_id = ? AND is_active = 1 ORDER BY name',
                [$clinicId]
            ),
            'canManage' => Auth::can('clinic.manage'),
            'canOperate' => Auth::can('queue.operate'),
            'active' => 'breaks',
        ]);
    }

    public function start(): void
    {
        $clinicId = $this->clinicId();
        $result = (new BreakService())->start(
            $clinicId,
            Request::int('queue_id'),
            Request::string('kind', 'unspecified'),
            trim(Request::string('reason')),
            (int) Auth::id()
        );

        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Break started. Waiting patients keep their place and their estimate now includes the break.'
            : (string) $result['error']);
        back('/breaks');
    }

    public function end(): void
    {
        $clinicId = $this->clinicId();
        $result = (new BreakService())->end($clinicId, Request::int('queue_id'));

        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? sprintf('Break ended after %d minute%s. The board is calling again.', (int) $result['minutes'], (int) $result['minutes'] === 1 ? '' : 's')
            : (string) $result['error']);
        back('/breaks');
    }

    public function schedule(): void
    {
        $clinicId = $this->clinicId();
        if (!Auth::can('clinic.manage')) {
            abort(403, 'Only a clinic owner can plan a break.');
        }

        $doctorId = Request::int('doctor_id');
        $result = (new BreakService())->addScheduled(
            $clinicId,
            $doctorId > 0 ? $doctorId : null,
            Request::int('weekday', -1),
            Request::string('starts_at'),
            Request::string('ends_at'),
            Request::string('kind', 'lunch')
        );

        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Planned. The board will show when the queue is next due to pause.'
            : (string) $result['error']);
        back('/breaks');
    }

    public function unschedule(string $id): void
    {
        $clinicId = $this->clinicId();
        if (!Auth::can('clinic.manage')) {
            abort(403, 'Only a clinic owner can change a planned break.');
        }

        $ok = (new BreakService())->removeScheduled($clinicId, (int) $id);
        flash($ok ? 'success' : 'error', $ok ? 'Planned break removed.' : 'That planned break does not exist in this clinic.');
        back('/breaks');
    }
}
