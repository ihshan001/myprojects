<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Env;
use App\Core\Log;
use App\Core\Str;

/**
 * Email verification for clinic owners.
 *
 * The rule: a clinic can sign in and configure itself immediately, but it
 * cannot put a patient in a queue until somebody has proved they can receive
 * mail at the address on the account.
 *
 * Why not block sign-in entirely? Because a clinic that has just registered
 * and hits a wall has no way to tell "the product is broken" from "check your
 * inbox", and because a mail outage would take the product down for the
 * clinics that are already running. Blocking only the patient-facing action
 * bounds the damage to the thing verification is actually about.
 *
 * Same token discipline as password resets: SHA-256 at rest, single use,
 * expiring, one live token per user, and a super admin can vouch for an
 * address by hand when mail is not reaching somebody.
 */
final class EmailVerificationService
{
    /** How long a link stays good. Long enough for a slow inbox, short enough to matter. */
    private const VALID_HOURS = 48;

    /** Minimum gap between resends, in seconds. */
    private const RESEND_SECONDS = 60;

    /**
     * Issue a token and mail it.
     *
     * @return string|null the raw token (for the caller to log in dev), or null
     *                     when the address is already verified or the user is gone
     */
    public function send(int $userId, bool $force = false): ?string
    {
        $user = Db::one(
            'SELECT id, name, email, clinic_id, email_verified_at, verification_sent_at FROM users WHERE id = ?',
            [$userId]
        );
        if (!$user) {
            return null;
        }
        if ($user['email_verified_at'] !== null) {
            return null;
        }

        if (!$force && $user['verification_sent_at'] !== null) {
            $last = strtotime((string) $user['verification_sent_at']);
            if ($last !== false && (time() - $last) < self::RESEND_SECONDS) {
                return null;
            }
        }

        // One live token per user: a resend invalidates the previous link so an
        // old email cannot be replayed weeks later.
        Db::run('DELETE FROM email_verifications WHERE user_id = ?', [(int) $user['id']]);

        $token = Str::urlToken(32);
        $expires = date('Y-m-d H:i:s', time() + self::VALID_HOURS * 3600);

        Db::insert(
            'INSERT INTO email_verifications (user_id, token_hash, expires_at, created_at) VALUES (?,?,?,?)',
            [(int) $user['id'], hash('sha256', $token), $expires, Db::now()]
        );
        Db::run('UPDATE users SET verification_sent_at = ? WHERE id = ?', [Db::now(), (int) $user['id']]);

        $url = rtrim((string) Env::get('app.url', ''), '/') . '/verify-email/' . $token;
        $clinic = Db::scalar('SELECT name FROM clinics WHERE id = ?', [(int) $user['clinic_id']]);

        (new MailService())->send(
            (string) $user['email'],
            'Confirm your email for ' . ($clinic ?: 'QueuePilot'),
            "Hello " . $user['name'] . ",\n\n"
            . "Confirm this address so your clinic can start issuing tokens:\n\n"
            . $url . "\n\n"
            . "The link is good for " . self::VALID_HOURS . " hours and works once.\n\n"
            . "If you did not create this account, ignore this email — nothing happens until the link is opened."
        );

        Log::info('auth.verification_sent', [
            'user_id' => (int) $user['id'],
            'email_hash' => substr(hash('sha256', (string) $user['email']), 0, 12),
        ]);

        return $token;
    }

    /**
     * Consume a token.
     *
     * @return int|null the verified user id, or null when the token is unknown,
     *                  expired or already used
     */
    public function verify(string $token): ?int
    {
        if ($token === '' || strlen($token) < 20) {
            return null;
        }

        $row = Db::one(
            'SELECT id, user_id, expires_at, used_at FROM email_verifications WHERE token_hash = ?',
            [hash('sha256', $token)]
        );
        if (!$row) {
            return null;
        }
        if ($row['used_at'] !== null) {
            return null;
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        Db::run('UPDATE email_verifications SET used_at = ? WHERE id = ?', [Db::now(), (int) $row['id']]);
        Db::run(
            'UPDATE users SET email_verified_at = ?, is_active = 1 WHERE id = ?',
            [Db::now(), (int) $row['user_id']]
        );

        Log::info('auth.verification_completed', ['user_id' => (int) $row['user_id']]);
        return (int) $row['user_id'];
    }

    /** A super admin vouching for an address when mail is not arriving. */
    public function verifyByHand(int $userId, int $actorId): bool
    {
        $user = Db::one('SELECT id, clinic_id FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            return false;
        }

        Db::run('UPDATE users SET email_verified_at = ?, is_active = 1 WHERE id = ?', [Db::now(), $userId]);
        Db::run('DELETE FROM email_verifications WHERE user_id = ?', [$userId]);
        (new AuditService())->log(
            (int) $user['clinic_id'],
            'user.email_verified_by_staff',
            'user',
            $userId,
            ['actor' => $actorId]
        );
        return true;
    }

    /** Whether this clinic may issue tokens to patients. */
    public function clinicMayIssueTokens(int $clinicId): bool
    {
        $unverified = Db::scalar(
            "SELECT COUNT(*) FROM users
              WHERE clinic_id = ? AND role = 'clinic_admin' AND is_active = 1 AND email_verified_at IS NULL",
            [$clinicId]
        );
        return (int) $unverified === 0;
    }

    /** The owner's address for a clinic, so a banner can name it. */
    public function ownerEmail(int $clinicId): ?string
    {
        $email = Db::scalar(
            "SELECT email FROM users WHERE clinic_id = ? AND role = 'clinic_admin' ORDER BY id LIMIT 1",
            [$clinicId]
        );
        return $email !== null ? (string) $email : null;
    }
}
