<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Services\MonitorService;

/**
 * Machine-readable health endpoint for an external monitor.
 *
 * Two ways in, both explicit:
 *   1. `Authorization: Bearer <MONITOR_TOKEN>` — the normal path for a probe.
 *   2. A signed-in super admin — so a person can look without a token.
 *
 * With no MONITOR_TOKEN set the endpoint answers 404 to everyone except a
 * signed-in super admin. An unauthenticated health page that lists tenants,
 * queue names and staff emails is an information leak, and "it is only
 * health data" is how those leaks get shipped.
 */
final class MonitorController
{
    public function show(): void
    {
        $token = trim((string) Env::get('monitor.token', ''));
        $provided = $this->bearer();

        $authorised = false;
        if ($token !== '' && $provided !== null && hash_equals($token, $provided)) {
            $authorised = true;
        } elseif (\App\Core\Auth::isSuper()) {
            $authorised = true;
        }

        if (!$authorised) {
            // Same response whether the endpoint is unconfigured or the token
            // was wrong: distinguishing them tells an attacker which hosts are
            // worth attacking.
            Response::json(['ok' => false, 'error' => 'not_found'], 404);
            exit;
        }

        $service = new MonitorService();
        // A clinic-scoped probe (per-branch monitoring) uses ?clinic=<id>,
        // but platform-wide checks stay available to the super admin only.
        $clinicId = Request::int('clinic');
        $result = $service->run($clinicId > 0 ? $clinicId : null);

        $status = match ($result['status']) {
            'critical' => 503,
            'warning'  => 200,
            default    => 200,
        };

        Response::json([
            'ok'           => $result['status'] !== 'critical',
            'status'       => $result['status'],
            'checks'       => $result['checks'],
            'generated_at' => $result['generated_at'],
            'version'      => '1',
        ], $status);
        exit;
    }

    private function bearer(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        if (preg_match('/^Bearer\s+(.+)$/i', (string) $header, $m) === 1) {
            return trim($m[1]);
        }
        return null;
    }
}
