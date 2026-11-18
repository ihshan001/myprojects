<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Log;
use App\Core\Str;

/**
 * Welcome a clinic that has just been approved.
 *
 * The owner needs three facts they do not have: the address patients will use,
 * the address staff will use, and a way in. The temptation is to generate a
 * password and put it in the email — it makes the email a single readable
 * message and support can say "check your inbox". It is also the weakest
 * possible way to start an account:
 *
 *   * the password lives in an inbox forever, readable by anyone who ever sees
 *     that mailbox or forwards the message;
 *   * it passes through the sending provider's logs;
 *   * "change it later" is a promise nobody keeps, so the clinic runs on a
 *     password that was written by a machine and read by whoever set the
 *     clinic up.
 *
 * So the email carries a single-use link instead. The owner chooses their own
 * password, which is stronger, and no credential ever travels.
 *
 * The token is stored hashed, so a database dump does not hand out access to
 * every clinic that has not yet clicked. It expires, it is single-use, and
 * accepting it is what marks the address verified — clicking a link delivered
 * to that address is stronger evidence than a code typed back into a form.
 */
final class WelcomeService
{
    /** How long a welcome link stays usable. */
    public const LINK_TTL_DAYS = 14;

    /**
     * Issue a welcome link for a clinic's owner and return the raw token.
     *
     * The raw token is returned rather than stored so the caller can put it in
     * the one email and then forget it. Nothing in the system can recover it
     * afterwards, which is the point.
     *
     * @return array{ok:bool, error?:string, token?:string, email?:string, name?:string}
     */
    public function issue(int $clinicId, ?int $invitedBy = null): array
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]);
        if ($clinic === null) {
            return ['ok' => false, 'error' => 'That clinic does not exist.'];
        }

        $owner = Db::one(
            "SELECT id, name, email FROM users
              WHERE clinic_id = ? AND role = 'clinic_admin'
              ORDER BY id LIMIT 1",
            [$clinicId]
        );
        if ($owner === null) {
            return ['ok' => false, 'error' => 'That clinic has no owner account.'];
        }
        $email = mb_strtolower((string) $owner['email']);
        if ($email === '') {
            return ['ok' => false, 'error' => 'The owner account has no email address.'];
        }

        // A previous link for this clinic is superseded, not left alive. Two
        // valid welcome links for one account is two ways in and one more
        // thing to reason about when something goes wrong.
        Db::run(
            "UPDATE staff_invites SET accepted_at = ? WHERE clinic_id = ? AND accepted_at IS NULL",
            [Db::now(), $clinicId]
        );

        $token = Str::urlToken(32);
        Db::insert(
            'INSERT INTO staff_invites (clinic_id, email, role, token_hash, invited_by, expires_at, created_at)
             VALUES (?,?,?,?,?,?,?)',
            [
                $clinicId, $email, 'clinic_admin',
                hash('sha256', $token), $invitedBy,
                date('Y-m-d H:i:s', strtotime('+' . self::LINK_TTL_DAYS . ' days')),
                Db::now(),
            ]
        );

        Log::info('clinic.welcome_issued', ['clinic' => $clinicId, 'owner' => (int) $owner['id']]);

        return ['ok' => true, 'token' => $token, 'email' => $email, 'name' => (string) $owner['name']];
    }

    /**
     * Send the welcome email, with both addresses and the link.
     *
     * Takes the token from issue() rather than generating its own, so the two
     * steps can be retried independently: a mail failure must not consume the
     * link, and a re-send must not orphan one.
     *
     * @return array{ok:bool, sent:bool, error?:string}
     */
    public function send(array $clinic, string $token, string $email, string $name): array
    {
        $siteUrl = \App\Core\Tenant::siteUrl($clinic);
        $opsUrl  = \App\Core\Tenant::opsUrl($clinic);
        $link    = rtrim($opsUrl, '/') . '/welcome/' . $token;

        $body = "Hello {$name},\n\n"
            . "Your clinic {$clinic['name']} has been approved and is ready.\n\n"
            . "Two addresses, and they do different jobs:\n\n"
            . "  Patients and the waiting-room screen:\n    {$siteUrl}\n\n"
            . "  Staff sign-in and everything you manage:\n    {$opsUrl}\n\n"
            . "Keep the second one private. It is deliberately hard to guess, so it\n"
            . "will not be found by somebody trying clinic names — do not publish it.\n\n"
            . "Set your password here, and you are ready to see your first patient:\n\n"
            . "  {$link}\n\n"
            . "That link works once and expires in " . self::LINK_TTL_DAYS . " days. If it\n"
            . "lapses, ask us and we will send another.\n\n"
            . "Inside, the Getting Started page lists what is still to configure:\n"
            . "your consulting rooms, your opening hours, and your doctors' names.\n\n"
            . "— QueuePilot";

        $sent = (new MailService())->send($email, 'Your clinic is ready', $body);
        Log::info('clinic.welcome_sent', ['clinic' => (int) $clinic['id'], 'sent' => $sent]);

        return ['ok' => true, 'sent' => $sent];
    }

    /**
     * Redeem a welcome token and set the password.
     *
     * @return array{ok:bool, error?:string, user_id?:int, clinic_id?:int}
     */
    public function redeem(string $token, string $password): array
    {
        $hash = hash('sha256', $token);
        $invite = Db::one(
            "SELECT * FROM staff_invites
              WHERE token_hash = ? AND accepted_at IS NULL
              ORDER BY id DESC LIMIT 1",
            [$hash]
        );

        if ($invite === null) {
            return ['ok' => false, 'error' => 'This link is not valid. It may already have been used.'];
        }
        if (strtotime((string) $invite['expires_at']) < time()) {
            return ['ok' => false, 'error' => 'This link has expired. Ask us for a new one.'];
        }

        $min = (int) \App\Core\Env::get('security.password_min', 10);
        if (mb_strlen($password) < $min) {
            return ['ok' => false, 'error' => "Use at least {$min} characters."];
        }

        $clinicId = (int) $invite['clinic_id'];
        $user = Db::one(
            "SELECT id FROM users WHERE clinic_id = ? AND email = ? ORDER BY id LIMIT 1",
            [$clinicId, mb_strtolower((string) $invite['email'])]
        );
        if ($user === null) {
            return ['ok' => false, 'error' => 'The account this link belongs to no longer exists.'];
        }

        $userId = (int) $user['id'];

        Db::transaction(function () use ($invite, $userId, $password, $clinicId): void {
            // No updated_at on users: the table never had one, and inventing
            // the column here would be a migration hiding inside a service.
            Db::run(
                'UPDATE users SET password_hash = ?, must_change_password = 0, is_active = 1,
                        email_verified_at = COALESCE(email_verified_at, ?)
                  WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), Db::now(), $userId]
            );
            Db::run('UPDATE staff_invites SET accepted_at = ?, accepted_user_id = ? WHERE id = ?',
                [Db::now(), $userId, (int) $invite['id']]);

            (new AuditService())->log($clinicId, 'user.welcome_redeemed', 'user', $userId);
        });

        Log::info('clinic.welcome_redeemed', ['clinic' => $clinicId, 'user' => $userId]);

        return ['ok' => true, 'user_id' => $userId, 'clinic_id' => $clinicId];
    }
}
