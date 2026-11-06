<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\ReportService;

final class ReportController
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
        $service = new ReportService();
        $date = Request::string('date', \App\Core\Db::today());
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = \App\Core\Db::today();
        }
        $from = Request::string('from', date('Y-m-d', strtotime('-7 days')));
        $to = Request::string('to', \App\Core\Db::today());

        view('reports/index', [
            'title' => 'Reports',
            'date' => $date,
            'from' => $from,
            'to' => $to,
            'summary' => $service->daySummary($clinicId, $date),
            // Satisfaction travels with the other reports because it is read in
            // the same breath as throughput, and because its response rate is
            // meaningless without the visit count beside it.
            'feedback' => (new \App\Services\FeedbackService())->report($clinicId, $from, $to),
            'byHour' => $service->byHour($clinicId, $date),
            'byDoctor' => $service->byDoctor($clinicId, $from, $to),
            'byDepartment' => $service->byDepartment($clinicId, $from, $to),
            'outcomeStats' => $service->outcomes($clinicId, $from, $to),
            'staffActivity' => Auth::can('clinic.manage') ? $service->staffActivity($clinicId, $from, $to) : [],
            'delays' => $service->delays($clinicId, $from, $to),
            'waits' => $service->waitPercentiles($clinicId, $from, $to),
            // Billed, not collected: the system knows each doctor's fee and
            // never sees the money. The view is careful with that word.
            'revenue' => $service->revenue($clinicId, $from, $to),
        ]);
    }

    public function export(): void
    {
        $clinicId = $this->clinicId();
        $from = Request::string('from', \App\Core\Db::today());
        $to = Request::string('to', \App\Core\Db::today());
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            abort(400, 'Invalid date range.');
            return;
        }

        $csv = (new ReportService())->csv($clinicId, $from, $to);
        $filename = sprintf('queuepilot-%s-to-%s.csv', $from, $to);

        (new AuditService())->log($clinicId, 'report.exported', 'report', null, [
            'from' => $from, 'to' => $to, 'rows' => substr_count($csv, "\n"),
        ]);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        // BOM so Excel opens UTF-8 (Sinhala/Tamil names) correctly
        echo "\xEF\xBB\xBF" . $csv;
    }

    public function audit(): void
    {
        $clinicId = $this->clinicId();
        view('audit/index', [
            'title' => 'Audit log',
            'entries' => (new AuditService())->forClinic($clinicId, 150, Request::string('action') ?: null),
            'filter' => Request::string('action'),
        ]);
    }
}
