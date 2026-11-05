<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\IncidentService;

/**
 * Incident records.
 *
 * The screen exists so that the story of an outage is written down while it is
 * happening, by the people it is happening to, and cannot be tidied up later.
 * Opening one is a single form; everything after that is an appended entry.
 */
final class IncidentController
{
    public function __construct(
        private readonly IncidentService $incidents = new IncidentService(),
        private readonly AuditService $audit = new AuditService(),
    ) {}

    private function clinicId(): int
    {
        $id = Auth::clinicId();
        if ($id === null) {
            abort(403, 'Incidents belong to a clinic.');
        }
        return $id;
    }

    public function index(): void
    {
        $clinicId = $this->clinicId();

        view('incidents/index', [
            'title'     => 'Incidents',
            'incidents' => $this->incidents->list($clinicId),
            'stats'     => $this->incidents->stats($clinicId),
            'alerts'    => $this->incidents->openAlerts(),
            'severities' => IncidentService::SEVERITIES,
            'errors'    => [],
            'old'       => [],
        ]);
    }

    public function show(string $id): void
    {
        $clinicId = $this->clinicId();
        $incident = $this->incidents->find($clinicId, (int) $id);
        if ($incident === null) {
            abort(404, 'Incident not found.');
        }

        view('incidents/show', [
            'title'    => $incident['reference'] . ' — ' . $incident['title'],
            'incident' => $incident,
            'entries'  => $this->incidents->entries((int) $incident['id']),
            'timings'  => $this->incidents->timings($incident),
            'kinds'    => IncidentService::KINDS,
            'statuses' => IncidentService::STATUSES,
        ]);
    }

    public function openIncident(): void
    {
        $clinicId = $this->clinicId();
        $in = Request::payload();

        try {
            $id = $this->incidents->open($clinicId, $in, Auth::id());
        } catch (\InvalidArgumentException $e) {
            view('incidents/index', [
                'title'      => 'Incidents',
                'incidents'  => $this->incidents->list($clinicId),
                'stats'      => $this->incidents->stats($clinicId),
                'alerts'     => $this->incidents->openAlerts(),
                'severities' => IncidentService::SEVERITIES,
                'errors'     => [$e->getMessage()],
                'old'        => $in,
            ]);
            return;
        }

        $this->audit->log($clinicId, 'incident.opened', 'incident', $id, [
            'severity' => (string) ($in['severity'] ?? 'minor'),
        ]);
        flash('success', 'Incident opened. Write down what you see as you go — the timeline is what makes the post-mortem possible.');
        redirect('/incidents/' . $id);
    }

    public function addEntry(string $id): void
    {
        $clinicId = $this->clinicId();
        $incident = $this->incidents->find($clinicId, (int) $id);
        if ($incident === null) {
            abort(404, 'Incident not found.');
        }

        $kind = Request::string('kind', 'note');
        $body = Request::string('body');
        try {
            $this->incidents->addEntry((int) $incident['id'], $kind, $body, Auth::id());
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect('/incidents/' . $incident['id']);
            return;
        }
        redirect('/incidents/' . $incident['id']);
    }

    public function acknowledge(string $id): void
    {
        $clinicId = $this->clinicId();
        $changed = $this->incidents->acknowledge($clinicId, (int) $id, Auth::id());
        flash($changed ? 'success' : 'error', $changed
            ? 'Acknowledged. The response clock started when it was detected, not now.'
            : 'That incident was already acknowledged — the first acknowledgement is the one that counts.');
        redirect('/incidents/' . (int) $id);
    }

    public function mitigate(string $id): void
    {
        $clinicId = $this->clinicId();
        $changed = $this->incidents->mitigate($clinicId, (int) $id, Request::string('body', 'Mitigated.'), Auth::id());
        flash($changed ? 'success' : 'error', $changed ? 'Marked mitigated.' : 'That incident is already resolved.');
        redirect('/incidents/' . (int) $id);
    }

    public function resolve(string $id): void
    {
        $clinicId = $this->clinicId();
        $changed = $this->incidents->resolve($clinicId, (int) $id, Request::string('body', 'Resolved.'), Auth::id());
        flash($changed ? 'success' : 'error', $changed ? 'Resolved. Record the cause while it is fresh.' : 'That incident is already resolved.');
        redirect('/incidents/' . (int) $id);
    }
}
