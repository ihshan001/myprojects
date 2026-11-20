<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Log;
use App\Core\Str;

/**
 * Give an approved clinic its addresses.
 *
 * A clinic that has been approved but has no hostname cannot be used by
 * anybody: the owner has no URL to open, the display board has nowhere to
 * live, and tracking links point at the platform root. So provisioning is part
 * of approval, not a follow-up task.
 *
 * Two addresses, and the difference between them is the security model:
 *
 *   site_host  <slug>.queue.lk        public, guessable, patient-facing.
 *                                     It is discoverable by design — patients
 *                                     are given the URL on a slip of paper.
 *   ops_host   <slug><code>.queue.lk  staff backend. The code makes it
 *                                     unguessable so the admin surface cannot
 *                                     be found by trying clinic names. It is
 *                                     obscurity, not authorisation: the login
 *                                     is still the login. But it removes the
 *                                     entire class of attacker who scans
 *                                     /admin on every clinic they can name.
 *
 * Idempotent by design. Approving a clinic twice, or re-running provisioning
 * after a failure, must not mint a second ops address that the owner has not
 * been told about — the first one is the one in their email.
 */
final class ProvisionService
{
    private const OPS_CODE_LENGTH = 5;

    /**
     * Ensure the clinic has both hosts. Returns them, plus whether anything
     * changed, so the caller knows whether to send an address email.
     *
     * @return array{site_host:string, ops_host:string, created:bool}
     */
    public function provision(array $clinic): array
    {
        $clinicId = (int) $clinic['id'];
        $siteHost = (string) ($clinic['site_host'] ?? '');
        $opsHost  = (string) ($clinic['ops_host'] ?? '');
        $opsCode  = (string) ($clinic['ops_code'] ?? '');
        $created  = false;

        $suffix = trim((string) \App\Core\Env::get('app.domain', ''));
        if ($suffix === '') {
            // No domain configured: this is a single-host deployment (dev, the
            // test suite, or a first install before DNS is set up). Approval
            // still works; there is simply no subdomain to mint.
            return ['site_host' => '', 'ops_host' => '', 'created' => false];
        }

        if ($siteHost === '') {
            $siteHost = $this->uniqueSiteLabel($clinic) . '.' . $suffix;
            $created = true;
        }

        if ($opsHost === '' || $opsCode === '') {
            $opsCode = $this->uniqueOpsCode($clinic);
            $opsHost = $this->siteLabel($siteHost) . $opsCode . '.' . $suffix;
            $created = true;
        }

        Db::run(
            'UPDATE clinics SET site_host = ?, ops_host = ?, ops_code = ?, updated_at = ? WHERE id = ?',
            [$siteHost, $opsHost, $opsCode, Db::now(), $clinicId]
        );

        Log::info('clinic.provisioned', [
            'clinic' => $clinicId, 'site' => $siteHost, 'ops' => $opsHost,
        ]);

        return ['site_host' => $siteHost, 'ops_host' => $opsHost, 'created' => $created];
    }

    /** The slug part of a site host, so the ops host shares the name. */
    private function siteLabel(string $siteHost): string
    {
        $dot = strpos($siteHost, '.');
        return $dot === false ? $siteHost : substr($siteHost, 0, $dot);
    }

    /**
     * A site label that is free.
     *
     * Two clinics called "City Clinic" must not collide, and a slug is also a
     * public identifier, so it is derived from the clinic's own slug and only
     * suffixed when it is genuinely taken.
     */
    private function uniqueSiteLabel(array $clinic): string
    {
        $base = (string) ($clinic['slug'] ?? '');
        if ($base === '') {
            $base = Str::slug((string) ($clinic['name'] ?? 'clinic'));
        }
        $base = $base !== '' ? $base : 'clinic';

        $candidate = $base;
        $n = 1;
        while (Db::scalar(
            'SELECT id FROM clinics WHERE (site_host = ? OR site_host LIKE ?) AND id <> ?',
            [$candidate, $candidate . '.%', (int) $clinic['id']]
        )) {
            $candidate = $base . (++$n);
        }
        return $candidate;
    }

    /**
     * An unguessable ops code.
     *
     * Lowercase alphanumerics only: the code appears in a hostname, so it must
     * survive being typed, read aloud and copied out of an email. No hyphens —
     * a hyphen and a dash are the same character to a person and different
     * characters to DNS, which is a support call waiting to happen.
     */
    private function uniqueOpsCode(array $clinic): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789'; // no i/l/o/0/1
        $max = strlen($alphabet) - 1;

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $code = '';
            for ($i = 0; $i < self::OPS_CODE_LENGTH; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }
            if (!Db::scalar('SELECT id FROM clinics WHERE ops_code = ? AND id <> ?', [$code, (int) $clinic['id']])) {
                return $code;
            }
        }
        // 32^5 is ~33 million; forty collisions in a row is not bad luck.
        throw new \RuntimeException('Could not allocate an operations address.');
    }
}
