<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\PriorityImpactService;
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
        private readonly PriorityImpactService $impact = new PriorityImpactService(),
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
     * The tiles are measurements, not decoration: the active-rule count and the
     * live override count are counted, the wait figures come from
     * PriorityImpactService with a sample floor, and "last updated" is the newest
     * rule change. Anything that cannot be measured yet arrives as null and the
     * view prints a dash.
     */
    private function payload(int $clinicId, array $old = [], array $errors = []): array
    {
        $allRules = $this->rules->rules($clinicId, false);
        $activeCount = count(array_filter($allRules, static fn (array $r): bool => (int) $r['is_active'] === 1));

        // Search is a GET parameter, not a client-side filter, so the list is
        // still searchable with JavaScript off. `position` is assigned BEFORE
        // filtering: it is the rule's true rank in the engine, not its index in
        // the visible subset, or the number would misstate which rule wins.
        $q = trim(Request::string('q', ''));
        $needle = mb_strtolower($q);
        // Status is a real filter, not a decorative button: "off" is how a
        // manager finds the rules they suspended without deleting them.
        $status = Request::string('status', 'all');
        if (!in_array($status, ['all', 'active', 'off'], true)) {
            $status = 'all';
        }
        $rules = [];
        $position = 0;
        foreach ($allRules as $rule) {
            $rule['matches'] = $this->rules->describeMatch($rule);
            $rule['position'] = ++$position;
            $isActive = (int) $rule['is_active'] === 1;
            if ($status === 'active' && !$isActive) {
                continue;
            }
            if ($status === 'off' && $isActive) {
                continue;
            }
            $haystack = mb_strtolower($rule['name'] . ' ' . ($rule['queue_name'] ?? '') . ' ' . ($rule['reason'] ?? ''));
            if ($needle === '' || str_contains($haystack, $needle)) {
                $rules[] = $rule;
            }
        }

        // Seven days inclusive of today, so the panel's "last 7 days" label is
        // arithmetically true. `-7 days` would span eight calendar days.
        $from = Request::string('from', date('Y-m-d', strtotime('-6 days')));
        $to = Request::string('to', Db::today());

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
            'tab'      => Request::string('tab', 'active') === 'history' ? 'history' : 'active',
            'history'  => $this->rules->history($clinicId),
            'activeCount' => $activeCount,
            'q'        => $q,
            'status'   => $status,
            // Dragging posts the whole order, so it is only offered when the
            // visible list IS the whole list.
            'filtering' => $q !== '' || $status !== 'all',
            'overridingNow' => $this->impact->overridingNow($clinicId, Db::today()),
            'impact'   => $this->impact->measure($clinicId, $from, $to),
            'lastChangedAt' => $this->rules->lastChangedAt($clinicId),
            // The overuse report answers a different question — who is overriding
            // by hand — and keeps its own longer window.
            'overuse'  => (new \App\Services\PriorityAuditService())->report($clinicId, $from, $to),
            'from'     => $from,
            'to'       => $to,
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

    /**
     * Persist the order the manager dragged the list into.
     *
     * Rejects a partial or foreign list rather than applying half of it: two
     * rules sharing a position would be ordered by id, which is precisely the
     * invisible tiebreak this screen exists to remove.
     */
    public function reorder(): void
    {
        $clinicId = $this->clinicId();
        $raw = Request::payload()['order'] ?? [];
        if (is_string($raw)) {
            $raw = array_filter(explode(',', $raw), static fn (string $v): bool => trim($v) !== '');
        }
        if (!is_array($raw) || !$this->rules->reorder($clinicId, array_values($raw), Auth::id())) {
            flash('error', 'That order did not match this clinic\'s rules. The list has been reloaded.');
            redirect('/queues/priority-rules');
            return;
        }

        $this->audit->log($clinicId, 'priority_rule.reordered', 'priority_rule', null, [
            'order' => array_map('intval', $raw),
        ]);
        flash('success', 'Order saved. The first matching rule now decides the next token.');
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
