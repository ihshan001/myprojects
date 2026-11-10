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

    /**
     * Everything the screen reads, built once.
     *
     * One builder for every render path. The error path used to assemble its
     * own shorter array, which meant an invalid rule redisplayed the page
     * without `overuse`, `from` or `to` — and the view read all three.
     * Duplicating a payload is how a form that fails validation renders a
     * different page from one that succeeds.
     *
     * @param array<string, mixed> $old
     * @param array<int, string>   $errors
     * @return array<string, mixed>
     */
    private function payload(int $clinicId, array $old = [], array $errors = []): array
    {
        $rules = $this->rules->rules($clinicId, false);
        foreach ($rules as &$rule) {
            $rule['matches'] = $this->rules->describeMatch($rule);
        }
        unset($rule);

        // The same window drives the overuse report and the impact
        // measurement, so the two panels can be read against each other
        // instead of describing different periods.
        $from = Request::string('from', date('Y-m-d', strtotime('-30 days')));
        $to   = Request::string('to', Db::today());
        $impact = new \App\Services\PriorityImpactService();

        return [
            'title'    => 'Priority rules',
            'rules'    => $rules,
            'activeCount' => count(array_filter(
                $rules,
                static fn (array $r): bool => (int) ($r['is_active'] ?? 0) === 1
            )),
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
            // The rules decide who gets seen first; the overuse report is what
            // shows whether they are still being used as intended. Both on one
            // screen so a rule and its consequences are read together.
            'overuse'  => (new \App\Services\PriorityAuditService())->report($clinicId, $from, $to),
            'from'     => $from,
            'to'       => $to,
            // Both are measurements rather than decoration, and both are
            // allowed to come back empty: a clinic that has not finished five
            // visits in a group gets a dash, because a mean of three is noise
            // dressed up as a finding.
            'impact'   => $impact->measure($clinicId, $from, $to),
            'overridingNow' => $impact->overridingNow($clinicId, Db::today()),
        ];
    }

    public function index(): void
    {
        view('queues/priority-rules', $this->payload($this->clinicId()));
    }

    public function save(): void
    {
        $clinicId = $this->clinicId();
        $in = Request::payload();

        try {
            $ruleId = $this->rules->create($clinicId, $in, Auth::id());
        } catch (\InvalidArgumentException $e) {
            // Redisplay with what was typed. Anything the service refused is
            // the one thing worth keeping: retyping a rule to change one
            // field is how a near-miss becomes a wrong rule.
            view('queues/priority-rules', $this->payload($clinicId, $in, [$e->getMessage()]));
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
