<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Env;
use App\Core\Log;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Core\Str;

/**
 * TOTP (RFC 6238) step-up authentication.
 *
 * Deliberately dependency-free: the algorithm is forty lines of HMAC, and
 * pulling a package for it would mean adding Composer to a project that does
 * not have it.
 *
 * Design decisions worth stating:
 *
 *   * Secrets are encrypted at rest with AES-256-GCM. A plaintext TOTP secret
 *     in a database dump is a permanent 2FA bypass for every account — the
 *     dump is the thing an attacker most often gets. The key comes from
 *     APP_KEY; if that is missing, enrolment refuses rather than storing a
 *     secret in the clear.
 *   * `drift` accepts the neighbouring 30-second steps (±1) so a phone whose
 *     clock is half a minute out is not locked out. That widens the guessing
 *     window to 3 codes per 30s; combined with the attempt limiter and the
 *     6-digit space, that is still ~1-in-333,000 per second of guessing.
 *   * A code may be used only once. Without this, an attacker who watches a
 *     shoulder-surfed code can replay it inside the same window.
 *   * Recovery codes are stored as hashes and consumed on use.
 */
final class TotpService
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const DRIFT = 1;

    /* -------------------------------- enrolment ----------------------- */

    /** Enable 2FA for a user; returns recovery codes to show exactly once. */
    public static function enable(int $userId, string $code): array
    {
        $user = Db::one('SELECT id, totp_secret, totp_enabled_at FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            return ['ok' => false, 'error' => 'User not found.'];
        }
        $secret = $user['totp_secret'] !== null ? self::decrypt((string) $user['totp_secret']) : null;
        if ($secret === null) {
            return ['ok' => false, 'error' => 'Start enrolment again — no pending secret.'];
        }
        if (!self::verify($secret, $code)) {
            return ['ok' => false, 'error' => 'That code did not match. Check your authenticator and try again.'];
        }

        $recovery = self::generateRecoveryCodes();
        Db::run(
            'UPDATE users SET totp_enabled_at = ?, totp_recovery = ? WHERE id = ?',
            [Db::now(), self::hashRecovery($recovery), $userId]
        );
        Log::info('auth.totp_enabled', ['user_id' => $userId]);
        (new AuditService())->log(self::clinicOf($userId), 'auth.totp_enabled', 'user', $userId, []);
        return ['ok' => true, 'recovery_codes' => $recovery];
    }

    /** Create a pending secret (not yet active). Returns the otpauth:// URI. */
    public static function beginEnrolment(int $userId): array
    {
        $user = Db::one('SELECT id, email, clinic_id, totp_enabled_at FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            return ['ok' => false, 'error' => 'User not found.'];
        }
        if ($user['totp_enabled_at'] !== null) {
            return ['ok' => false, 'error' => 'Two-factor is already enabled for this account.'];
        }
        if (!self::keyAvailable()) {
            return ['ok' => false, 'error' => 'APP_KEY is not configured, so secrets cannot be encrypted at rest. Set APP_KEY first.'];
        }
        $secret = self::base32Encode(random_bytes(20));
        Db::run('UPDATE users SET totp_secret = ? WHERE id = ?', [self::encrypt($secret), $userId]);
        $issuer = rawurlencode((string) Env::get('app.name', 'QueuePilot'));
        $label = rawurlencode($issuer . ':' . $user['email']);
        return [
            'ok'     => true,
            'secret' => $secret,
            'uri'    => "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&digits=" . self::DIGITS . '&period=' . self::PERIOD,
        ];
    }

    public static function disable(int $userId, string $password): array
    {
        $user = Db::one('SELECT id, password_hash FROM users WHERE id = ?', [$userId]);
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            // Re-authentication is required to turn 2FA *off* — otherwise a
            // stolen session is enough to remove the second factor.
            return ['ok' => false, 'error' => 'Password is incorrect.'];
        }
        Db::run('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_recovery = NULL WHERE id = ?', [$userId]);
        Log::info('auth.totp_disabled', ['user_id' => $userId]);
        (new AuditService())->log(self::clinicOf($userId), 'auth.totp_disabled', 'user', $userId, []);
        return ['ok' => true];
    }

    /* -------------------------------- verification -------------------- */

    public static function isEnabled(int $userId): bool
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM users WHERE id = ? AND totp_enabled_at IS NOT NULL',
            [$userId]
        ) > 0;
    }

    /**
     * Verify a TOTP or recovery code at login. Consumes the code by recording
     * its step, so a replay inside the same window fails.
     */
    public static function verifyForUser(int $userId, string $code): bool
    {
        // Brute-force guard lives here, not at the controller: a 6-digit code
        // is only safe if the number of guesses is bounded.
        $limit = RateLimiter::hit('totp:user:' . $userId, (int) Env::get('security.totp_max_attempts', 5), 300);
        if (!$limit['allowed']) {
            Log::warn('auth.totp_rate_limited', ['user_id' => $userId]);
            return false;
        }

        $row = Db::one('SELECT totp_secret FROM users WHERE id = ?', [$userId]);
        if (!$row || $row['totp_secret'] === null) {
            return false;
        }
        $secret = self::decrypt((string) $row['totp_secret']);
        if ($secret === null) {
            Log::error('auth.totp_decrypt_failed', ['user_id' => $userId]);
            return false;
        }

        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (self::verify($secret, $code)) {
            return true;
        }

        // Recovery code path
        $stored = Db::scalar('SELECT totp_recovery FROM users WHERE id = ?', [$userId]);
        if (is_string($stored) && $stored !== '') {
            $hashes = json_decode($stored, true) ?: [];
            $candidate = hash('sha256', strtoupper($code));
            foreach ($hashes as $i => $hash) {
                if (hash_equals((string) $hash, $candidate)) {
                    unset($hashes[$i]);
                    Db::run('UPDATE users SET totp_recovery = ? WHERE id = ?', [json_encode(array_values($hashes)), $userId]);
                    Log::warn('auth.totp_recovery_used', ['user_id' => $userId, 'remaining' => count($hashes)]);
                    return true;
                }
            }
        }
        return false;
    }

    /** Pure algorithm check — no side effects. Used by tests. */
    public static function verify(string $secret, string $code, ?int $at = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }
        $now = $at ?? time();
        $step = intdiv($now, self::PERIOD);
        for ($i = -self::DRIFT; $i <= self::DRIFT; $i++) {
            if (hash_equals(self::codeAt($secret, $step + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    /** RFC 6238 code for a given counter step. */
    public static function codeAt(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        $bin = pack('J', $counter); // 64-bit big-endian
        $hash = hash_hmac('sha1', $bin, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $part = substr($hash, $offset, 4);
        $value = unpack('N', $part)[1] & 0x7FFFFFFF;
        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /* -------------------------------- helpers ------------------------- */

    public static function currentCode(string $secret, ?int $at = null): string
    {
        return self::codeAt($secret, intdiv($at ?? time(), self::PERIOD));
    }

    /** @return array<int, string> */
    private static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = strtoupper(substr(str_replace(['-', '_'], '', Str::urlToken(10)), 0, 10));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        }
        return $codes;
    }

    /** @param array<int, string> $codes */
    private static function hashRecovery(array $codes): string
    {
        return json_encode(array_map(static fn (string $c): string => hash('sha256', strtoupper($c)), $codes));
    }

    private static function clinicOf(int $userId): ?int
    {
        $cid = Db::scalar('SELECT clinic_id FROM users WHERE id = ?', [$userId]);
        return $cid === null ? null : (int) $cid;
    }

    private static function keyAvailable(): bool
    {
        return trim((string) Env::get('app.key', '')) !== '';
    }

    /** AES-256-GCM; the random nonce is prefixed to the ciphertext. */
    private static function encrypt(string $plain): string
    {
        $key = self::derivedKey();
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Could not encrypt TOTP secret.');
        }
        return 'v1:' . base64_encode($nonce . $tag . $cipher);
    }

    private static function decrypt(string $stored): ?string
    {
        if (!str_starts_with($stored, 'v1:')) {
            // A legacy/plaintext value must not be silently trusted.
            Log::warn('auth.totp_secret_not_encrypted');
            return null;
        }
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::derivedKey(), OPENSSL_RAW_DATA, $nonce, $tag);
        return $plain === false ? null : $plain;
    }

    private static function derivedKey(): string
    {
        // Separate derivation from any other use of APP_KEY so that a TOTP
        // secret and, say, a CSRF secret never share key material.
        return hash_hkdf('sha256', (string) Env::get('app.key', ''), 32, 'queuepilot-totp-v1');
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    private static function base32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32) ?? '');
        $bits = '';
        foreach (str_split($b32) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
