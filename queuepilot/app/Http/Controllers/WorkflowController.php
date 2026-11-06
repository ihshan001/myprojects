<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\WorkflowService;

/**
 * Service workflows.
 *
 * A laboratory's visit is not complete when the nurse finishes talking; it is
 * complete when the sample is taken, the result is verified and the report is
 * handed over. This screen is where a clinic names those steps. Completing a
 * visit before they are ticked is refused in the service, not only hidden in
 * the UI — the API and the desk both obey it.
 */
final class WorkflowController
{
    public function __construct(
        private readonly WorkflowService $workflows = new WorkflowService(),
        private readonly AuditService $audit = new AuditService(),
    ) {}

    private function clinicId(): int
    {
        $id = Auth::clinicId();
        if ($id === null) {
            abort(403, 'Service workflows belong to a clinic.');
        }
        return $id;
    }

    public function index(): void
    {
        $clinicId = $this->clinicId();

        view('queues/workflows', [
            'title'   => 'Service workflows',
            'steps'   => $this->workflows->all($clinicId, false),
            'kinds'   => WorkflowService::KINDS,
            'queues'  => Db::all(
                "SELECT id, name, kind FROM queues WHERE clinic_id = ? AND session_date >= date('now', '-7 day')
                  ORDER BY session_date DESC, id DESC",
                [$clinicId]
            ),
            'old'     => [],
            'errors'  => [],
        ]);
    }

    public function save(): void
    {
        $clinicId = $this->clinicId();
        $in = Request::payload();

        try {
            $stepId = $this->workflows->create($clinicId, $in, Auth::id());
        } catch (\InvalidArgumentException $e) {
            view('queues/workflows', [
                'title'  => 'Service workflows',
                'steps'  => $this->workflows->all($clinicId, false),
                'kinds'  => WorkflowService::KINDS,
                'queues' => Db::all(
                    "SELECT id, name, kind FROM queues WHERE clinic_id = ? AND session_date >= date('now', '-7 day')
                      ORDER BY session_date DESC, id DESC",
                    [$clinicId]
                ),
                'old'    => $in,
                'errors' => [$e->getMessage()],
            ]);
            return;
        }

        $this->audit->log($clinicId, 'workflow_step.created', 'workflow_step', $stepId, [
            'name' => (string) ($in['name'] ?? ''),
        ]);
        flash('success', 'Step added. It applies to visits that have not been completed yet.');
        redirect('/queues/workflows');
    }

    public function delete(string $id): void
    {
        $clinicId = $this->clinicId();
        $stepId = (int) $id;
        $step = Db::one('SELECT id, name FROM workflow_steps WHERE id = ? AND clinic_id = ?', [$stepId, $clinicId]);
        if ($step === null) {
            abort(404, 'Step not found.');
        }
        $this->workflows->delete($clinicId, $stepId);
        $this->audit->log($clinicId, 'workflow_step.deleted', 'workflow_step', $stepId, ['name' => (string) $step['name']]);
        flash('success', 'Step removed.');
        redirect('/queues/workflows');
    }
}
