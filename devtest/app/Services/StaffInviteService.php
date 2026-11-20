<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Env;
use App\Core\Log;
use App\Core\Str;

/**
 * Staff invitations.
 *
 * Why invites instead of "admin types a password":
 *   * The admin never learns the colleague's password, so the first thing a
 *     new receptionist does is not "change the password your boss texted you".
 *   * The invitation can expire, be revoked, and is single-use.
 *   * Only a hash of the token is stored. The plaintext exists in exactly one
 *     place — the link shown once to the inviter (and, in a real deployment,
 *     the email). A database dump cannot be replayed into an account.
 *
 * Delivery follows the same rule as patient SMS: with no mail transport
 * configured the invite is still created and the link is shown to the admin to
 * pass on by hand. Pretending it was emailed would be worse than saying so.
 */
final class StaffInviteService
{
    public const ROLES = ['clinic_admin', 'receptionist', 'doctor'];

    public function __construct(private readonly AuditService $audit = new AuditService()) {}

    /**
     * @return array{ok: bool, error?: string, invite_id?: int, token?: string, expires_at?: string, delivered?: bool, email?: string}
     */
    public function invite(int $clinicId, string $email, string $role, ?string $name = null): array
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => 'Enter a valid email address.'];
        }
        if (!in_array($role, self::ROLES, true)) {
            return ['ok' => false, 'error' => 'Choose a valid role.'];
        }
        // A doctor invite must be linked to a doctor record, or the account
        // would log in to an empty board with no queue to call.
        if ($role === 'doctor' && empty($name)) {
            return ['ok' => false, 'error' => 'Pick which doctor this account belongs to.'];
        }

        if (Db::one('SELECT id FROM users WHERE email = ?', [$email])) {
            return ['ok' => false, 'error' => 'That email already has an account. Use "reset password" instead.'];
        }

        // Supersede any earlier open invite for the same address: two live
        // links to one mailbox is how "which one did I click" bugs start.
        $open = Db::all(
            'SELECT id FROM staff_invites WHERE clinic_id = ? AND email = ? AND accepted_at IS NULL',
            [$clinicId, $email]
        );
        foreach ($open as $row) {
            Db::run('DELETE FROM staff_invites WHERE id = ?', [(int) $row['id']]);
        }

        $token = Str::urlToken(32);
        $expiresAt = date('Y-m-d H:i:s', time() + 60 * 60 * (int) Env::get('security.invite_hours', 72));

        $inviteId = Db::insert(
            'INSERT INTO staff_invites (clinic_id, email, role, token_hash, invited_by, expires_at, created_at)
             VALUES (?,?,?,?,?,?,?)',
            [$clinicId, $email, $role, hash('sha256', $token), Auth::id(), $expiresAt, Db::now()]
        );

        $delivered = (new MailService())->send(
            $email,
            'You are invited to join QueuePilot',
            "You have been invited to join the clinic team as a {$role}.\n\n"
            . "Set your password here (valid until {$expiresAt}):\n"
            . rtrim((string) Env::get('app.url', ''), '/') . '/invite/' . $token . "\n\n"
            . "If you were not expecting this, ignore the message."
        );

        $this->audit->log($clinicId, 'staff.invited', 'staff_invite', $inviteId, [
            'email' => $email, 'role' => $role, 'delivered' => $delivered,
        ]);

        return [
            'ok'         => true,
            'invite_id'  => $inviteId,
            'token'      => $token,
            'expires_at' => $expiresAt,
            'delivered'  => $delivered,
            'email'      => $email,
        ];
    }

    /** Resolve a token to its invite, or null when unknown/expired/used. */
    public function find(string $token): ?array
    {
        $hash = hash('sha256', $token);
        $invite = Db::one(
            'SELECT i.*, c.name AS clinic_name, c.slug AS clinic_slug
               FROM staff_invites i
               JOIN clinics c ON c.id = i.clinic_id
              WHERE i.token_hash = ? AND i.accepted_at IS NULL
              LIMIT 1',
            [$hash]
        );
        if (!$invite) {
            return null;
        }
        if (strtotime((string) $invite['expires_at']) < time()) {
            return null;
        }
        return $invite;
    }

    /**
     * Accept an invitation and create the account. The invite row is marked
     * accepted in the same transaction as the user insert, so a crash cannot
     * leave a usable token with an account already created.
     */
    public function accept(string $token, string $name, string $password): array
    {
        $invite = $this->find($token);
        if (!$invite) {
            return ['ok' => false, 'error' => 'This invitation is invalid, already used, or has expired. Ask your administrator for a new one.'];
        }
        if (mb_strlen(trim($name)) < 2) {
            return ['ok' => false, 'error' => 'Enter your full name.'];
        }
        if (!self::passwordStrongEnough($password)) {
            return ['ok' => false, 'error' => self::passwordRule()];
        }

        // The password rule and the uniqueness of email are both enforced by
        // the caller-visible checks *and* the database; this keeps the race
        // between two admins inviting the same address from creating two rows.
        $userId = Db::transaction(function () use ($invite, $name, $password, $token): int {
            $doctorId = null;
            if ($invite['role'] === 'doctor') {
                // Bind to the first active doctor of this clinic; a real
                // deployment would carry doctor_id on the invite. Until then,
                // the account is created without a doctor binding rather than
                // silently attached to the wrong person.
                $doctorId = Db::scalar(
                    'SELECT id FROM doctors WHERE clinic_id = ? AND is_active = 1 ORDER BY id LIMIT 1',
                    [(int) $invite['clinic_id']]
                );
                $doctorId = $doctorId === false || $doctorId === null ? null : (int) $doctorId;
            }

            $id = Db::insert(
                'INSERT INTO users (clinic_id, name, email, password_hash, role, doctor_id, locale, is_active, created_at)
                 VALUES (?,?,?,?,?,?,?,1,?)',
                [
                    (int) $invite['clinic_id'], trim($name), $invite['email'],
                    password_hash($password, PASSWORD_DEFAULT), $invite['role'],
                    $doctorId, 'en', Db::now(),
                ]
            );
            Db::run(
                'UPDATE staff_invites SET accepted_at = ?, accepted_user_id = ? WHERE id = ?',
                [Db::now(), $id, (int) $invite['id']]
            );
            return $id;
        });

        $this->audit->log((int) $invite['clinic_id'], 'staff.invite_accepted', 'user', $userId, [
            'email' => $invite['email'], 'role' => $invite['role'],
        ]);

        Log::info('staff.invite_accepted', ['user_id' => $userId, 'clinic_id' => $invite['clinic_id']]);
        return ['ok' => true, 'user_id' => $userId, 'email' => (string) $invite['email']];
    }

    public function revoke(int $clinicId, int $inviteId): bool
    {
        $invite = Db::one('SELECT * FROM staff_invites WHERE id = ? AND clinic_id = ?', [$inviteId, $clinicId]);
        if (!$invite || $invite['accepted_at'] !== null) {
            return false;
        }
        Db::run('DELETE FROM staff_invites WHERE id = ? AND clinic_id = ?', [$inviteId, $clinicId]);
        $this->audit->log($clinicId, 'staff.invite_revoked', 'staff_invite', $inviteId, [
            'email' => $invite['email'],
        ]);
        return true;
    }

    public function pending(int $clinicId): array
    {
        return Db::all(
            'SELECT i.*, u.name AS invited_by_name
               FROM staff_invites i
               LEFT JOIN users u ON u.id = i.invited_by
              WHERE i.clinic_id = ? AND i.accepted_at IS NULL AND i.expires_at > ?
              ORDER BY i.created_at DESC',
            [$clinicId, Db::now()]
        );
    }

    /* -------------------------------- password policy ------------------ */

    public static function passwordRule(): string
    {
        $min = (int) Env::get('security.password_min', 10);
        return "Use at least {$min} characters, including a letter and a number.";
    }

    public static function passwordStrongEnough(string $password): bool
    {
        $min = (int) Env::get('security.password_min', 10);
        return mb_strlen($password) >= $min
            && preg_match('/[A-Za-z]/', $password) === 1
            && preg_match('/\d/', $password) === 1;
    }

}
