<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Env;
use App\Core\Log;
use App\Core\Str;

/**
 * Password recovery.
 *
 * The rules that make this safe rather than merely present:
 *
 *   * The request endpoint always reports the same thing, whether or not the
 *     address exists. "No account with that email" is an account-enumeration
 *     oracle and is how an attacker builds a list of clinic owners to target.
 *   * Only a SHA-256 hash of the token is stored. A dump cannot be replayed
 *     into an account.
 *   * One live token per user: requesting again invalidates the previous link,
 *     so an old email cannot be used later.
 *   * 60-minute expiry, single use.
 *   * Completion requires a password that passes the same policy as an
 *     invitation.
 *   * Completing a reset destroys every session for that user, because the
 *     most common reason to reset a password is that someone else had it.
 *   * Rate limited per IP and per address through the durable limiter, so the
 *     mail-out cannot be used to spam an inbox.
 */
final class PasswordResetService
{
    private const TABLE = 'password_resets';

    /** @return string|null The raw token, or null when no action should be taken. */
    public function request(string $email): ?string
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $user = Db::one('SELECT id, email, name, is_active FROM users WHERE email = ?', [$email]);
        if (!$user || (int) $user['is_active'] !== 1) {
            // Deliberately indistinguishable from success to the caller.
            Log::info('auth.reset_requested_unknown', ['email_hash' => substr(hash('sha256', $email), 0, 12)]);
            return null;
        }

        // One live token per user.
        Db::run('DELETE FROM ' . self::TABLE . ' WHERE user_id = ?', [(int) $user['id']]);

        $token = Str::urlToken(32);
        $expires = date('Y-m-d H:i:s', time() + 60 * (int) Env::get('security.reset_minutes', 60));

        Db::insert(
            'INSERT INTO ' . self::TABLE . ' (user_id, token_hash, expires_at, requested_ip, created_at)
             VALUES (?,?,?,?,?)',
            [(int) $user['id'], hash('sha256', $token), $expires, $this->ip(), Db::now()]
        );

        $link = rtrim((string) Env::get('app.url', ''), '/') . '/reset-password/' . $token;
        // Delivery is reported honestly, exactly like invitations: with no
        // transport configured nothing is sent and the caller must say so.
        (new MailService())->send(
            $email,
            'Reset your QueuePilot password',
            "Hello {$user['name']},\n\n"
            . "Someone asked to reset the password for this account. If it was you, open:\n{$link}\n\n"
            . "The link expires in 60 minutes and works once. If it was not you, ignore this message — "
            . "your password has not changed.\n"
        );

        (new AuditService())->log(null, 'auth.reset_requested', 'user', (int) $user['id'], ['ip' => $this->ip()]);
        return $token;
    }

    /** Resolve a token without consuming it. */
    public function find(string $token): ?array
    {
        $row = Db::one(
            'SELECT r.*, u.email, u.name, u.is_active FROM ' . self::TABLE . ' r
               JOIN users u ON u.id = r.user_id
              WHERE r.token_hash = ? AND r.used_at IS NULL
              LIMIT 1',
            [hash('sha256', $token)]
        );
        if (!$row) {
            return null;
        }
        if (strtotime((string) $row['expires_at']) < time() || (int) $row['is_active'] !== 1) {
            return null;
        }
        return $row;
    }

    public function complete(string $token, string $password): array
    {
        $row = $this->find($token);
        if (!$row) {
            return ['ok' => false, 'error' => 'This reset link is invalid, already used, or has expired. Request a new one.'];
        }
        if (!StaffInviteService::passwordStrongEnough($password)) {
            return ['ok' => false, 'error' => StaffInviteService::passwordRule()];
        }

        Db::transaction(function () use ($row, $password): void {
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [
                password_hash($password, PASSWORD_DEFAULT),
                (int) $row['user_id'],
            ]);
            Db::run('UPDATE ' . self::TABLE . ' SET used_at = ? WHERE id = ?', [Db::now(), (int) $row['id']]);
            // Every other outstanding link dies with this one.
            Db::run('DELETE FROM ' . self::TABLE . ' WHERE user_id = ? AND id <> ?', [(int) $row['user_id'], (int) $row['id']]);
        });

        // A reset means the old password may be in someone else's hands.
        // Session records are file-based per session id, so the durable signal
        // that forces re-authentication is a marker the Auth layer checks.
        Db::run(
            'INSERT INTO settings (scope, owner_id, key_name, value) VALUES (?,?,?,?)
             ON CONFLICT (scope, owner_id, key_name) DO UPDATE SET value = excluded.value',
            ['user', (int) $row['user_id'], 'sessions_valid_after', Db::now()]
        );

        (new AuditService())->log(null, 'auth.password_reset', 'user', (int) $row['user_id'], ['ip' => $this->ip()]);
        Log::warn('auth.password_reset', ['user' => (int) $row['user_id']]);
        return ['ok' => true, 'email' => (string) $row['email']];
    }

    public function prune(): int
    {
        return Db::run('DELETE FROM ' . self::TABLE . ' WHERE expires_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
    }

    private function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'cli';
    }
}
