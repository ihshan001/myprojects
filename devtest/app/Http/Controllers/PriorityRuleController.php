<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\PriorityRuleService;

/**
 * Priority rules.
 *
 * The screen exists because the rule engine is the part of priority handling
 * that a clinic manager cannot be expected to edit in PHP. Everything on this
 * page is a rule that already changes what the desk does; there is no draft
 * state and no preview, and the list shows what each rule will actually match.
 */
final class PriorityRuleController
{
    public function __construct(
        private readonly PriorityRuleService $rules = new PriorityRuleService(),
        private readonly AuditService $audit = new AuditService(),
    ) {}

    private function clinicId(): int
    {
        $id = Auth::clinicId();
        if ($id === null) {
            abort(403, 'Priority rules belong to a clinic.');
        }
        return $id;
    }

    public function index(): void
    {
        $clinicId = $this->clinicId();
        view('queues/priority-rules', $this->viewData($clinicId, [], []));
    }

    /**
     * Everything the screen reads, in one place.
     *
     * index() and the failed-save path used to build this separately, and the
     * error path silently omitted the report — so a rejected rule wiped the
     * right-hand column.
     */
    private function viewData(int $clinicId, array $old, array $errors): array
    {
        $from = Request::string('from', date('Y-m-d', strtotime('-30 days')));
        $to = Request::string('to', Db::today());

        $rules = $this->rules->rules($clinicId, false);
        $position = 0;
        foreach ($rules as &$rule) {
            $rule['matches'] = $this->rules->describeMatch($rule);
            $rule['position'] = ++$position;
        }
        unset($rule);

        return [
            'title'    => 'Priority rules',
            'rules'    => $rules,
            'doctors'  => Db::all('SELECT id, name FROM doctors WHERE clinic_id = ? ORDER BY name', [$clinicId]),
            'queues'   => Db::all(
                "SELECT id, name FROM queues WHERE clinic_id = ? AND session_date >= date('now', '-7 day')
                  ORDER BY session_date DESC, id DESC",
                [$clinicId]
            ),
            'sources'  => PriorityRuleService::SOURCES,
            'kinds'    => PriorityRuleService::KINDS,
            'old'      => $old,
            'errors'   => $errors,
            'stats'    => $this->rules->stats($clinicId, $from, $to),
            'history'  => $this->rules->history($clinicId),
            'overuse'  => (new \App\Services\PriorityAuditService())->report($clinicId, $from, $to),
            'from'     => $from,
            'to'       => $to,
        ];
    }

    /** Drag-to-reorder. Returns JSON so the list does not need a full reload. */
    public function reorder(): void
    {
        $clinicId = $this->clinicId();
        $in = Request::payload();
        $ids = $in['order'] ?? [];
        if (is_string($ids)) {
            $ids = json_decode($ids, true);
        }
        if (!is_array($ids)) {
            json(['ok' => false, 'error' => 'Send an ordered list of rule ids.'], 422);
            return;
        }

        try {
            $this->rules->reorder($clinicId, $ids);
        } catch (\InvalidArgumentException $e) {
            json(['ok' => false, 'error' => $e->getMessage()], 422);
            return;
        }

        $this->audit->log($clinicId, 'priority_rule.reordered', 'priority_rule', 0, [
            'order' => array_map('intval', $ids),
        ]);
        json(['ok' => true]);
    }

    public function save(): void
    {
        $clinicId = $this->clinicId();
        $in = Request::payload();

        try {
            $ruleId = $this->rules->create($clinicId, $in, Auth::id());
        } catch (\InvalidArgumentException $e) {
            view('queues/priority-rules', $this->viewData($clinicId, $in, [$e->getMessage()]));
            return;
        }

        $this->audit->log($clinicId, 'priority_rule.created', 'priority_rule', $ruleId, [
            'name'   => (string) ($in['name'] ?? ''),
            'effect' => (int) ($in['effect'] ?? 0),
        ]);

        flash('success', 'Rule saved. It applies to the next token issued, not to patients already waiting.');
        redirect('/queues/priority-rules');
    }

    public function toggle(string $id): void
    {
        $clinicId = $this->clinicId();
        $ruleId = (int) $id;
        $rule = Db::one('SELECT id, name, is_active FROM priority_rules WHERE id = ? AND clinic_id = ?', [$ruleId, $clinicId]);
        if ($rule === null) {
            abort(404, 'Rule not found.');
        }

        $active = (int) $rule['is_active'] === 1;
        $this->rules->setActive($clinicId, $ruleId, !$active);
        $this->audit->log($clinicId, $active ? 'priority_rule.disabled' : 'priority_rule.enabled', 'priority_rule', $ruleId, [
            'name' => (string) $rule['name'],
        ]);
        flash('success', $active
            ? 'Rule turned off. Entries it already decided keep their reason.'
            : 'Rule turned on. It applies from the next token.');
        redirect('/queues/priority-rules');
    }

    public function delete(string $id): void
    {
        $clinicId = $this->clinicId();
        $ruleId = (int) $id;
        $rule = Db::one('SELECT id, name FROM priority_rules WHERE id = ? AND clinic_id = ?', [$ruleId, $clinicId]);
        if ($rule === null) {
            abort(404, 'Rule not found.');
        }

        $this->rules->delete($clinicId, $ruleId);
        $this->audit->log($clinicId, 'priority_rule.deleted', 'priority_rule', $ruleId, ['name' => (string) $rule['name']]);
        flash('success', 'Rule deleted. Patients it already reordered keep their recorded reason.');
        redirect('/queues/priority-rules');
    }
}
