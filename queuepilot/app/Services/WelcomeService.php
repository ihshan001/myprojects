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
    /**
     * The URL a patient opens to see the queue.
     *
     * Prefers a named screen key, then the clinic-wide legacy token, and only
     * falls back to the bare site host if neither exists. A clinic that has
     * been approved always has at least one of them (signup writes the
     * clinic-wide token), so the fallback is for a half-migrated database,
     * not the normal path.
     */
    private function boardUrl(array $clinic): string
    {
        $clinicId = (int) $clinic['id'];
        $site     = rtrim(\App\Core\Tenant::siteUrl($clinic), '/');

        $key = (string) (Db::scalar(
            'SELECT display_key FROM public_display_keys
              WHERE clinic_id = ? AND revoked_at IS NULL AND kind = ?
              ORDER BY id LIMIT 1',
            [$clinicId, 'waiting_room']
        ) ?: '');

        if ($key === '') {
            $key = (string) (Db::scalar(
                "SELECT value FROM settings WHERE scope='clinic' AND owner_id=? AND key_name='display_token'",
                [$clinicId]
            ) ?: '');
        }

        return $key === '' ? $site : $site . '/board/' . $key;
    }

    public function send(array $clinic, string $token, string $email, string $name): array
    {
        $siteUrl = \App\Core\Tenant::siteUrl($clinic);
        $opsUrl  = \App\Core\Tenant::opsUrl($clinic);
        $link    = rtrim($opsUrl, '/') . '/welcome/' . $token;

        // The address that actually shows the queue. `siteUrl` is a bare host;
        // the board a patient opens is /board/<key>, and the key lives in the
        // clinic's settings. Printing only the host sent the owner to a page
        // with nothing on it — the one link they are told to put on a counter
        // card. Falls back to the host when no key exists yet, rather than
        // printing an address that 404s.
        $boardUrl = $this->boardUrl($clinic);

        // Say what actually happened. A clinic granted a trial has not been
        // "approved" in the sense a reader understands — nobody is being billed
        // yet — and the earlier wording told trial customers they had been.
        $status = (string) ($clinic['billing_status'] ?? 'active');
        $opening = $status === 'trial'
            ? "Your clinic {$clinic['name']} is on a free trial until "
                . ($clinic['trial_ends_on'] ?? 'the date shown in Billing')
                . ". Nothing is billed during the trial, and you can start seeing patients now."
            : "Your clinic {$clinic['name']} is approved and ready.";

        /* Two addresses, but only print the second when it is genuinely a second
         * one.
         *
         * A single-host install (no APP_DOMAIN, which is every XAMPP trial run and
         * any first deployment before DNS exists) has no per-clinic subdomains to
         * mint, so siteUrl() and opsUrl() both fall back to APP_URL. Printing
         * "Your clinic's public page: http://host" and "Staff sign-in: http://host"
         * as if they were different addresses is at best noise, and the sentence
         * that follows — keep that one private, it is hard to guess — is then
         * simply untrue about a URL the reader already knows. Say one address
         * once, and keep the warning only where it holds.
         */
        $hasSeparateOps = $opsUrl !== '' && rtrim($opsUrl, '/') !== rtrim($siteUrl, '/');

        $addresses = $hasSeparateOps
            ? "  The queue your patients watch — put this on the waiting-room TV\n"
                . "  or print it on a card:\n    {$boardUrl}\n\n"
                . ($siteUrl !== $boardUrl ? "  Your clinic's public page:\n    {$siteUrl}\n\n" : '')
                . "  Staff sign-in and everything you manage:\n    {$opsUrl}\n\n"
                . "Keep that last address private. It is deliberately hard to guess, so\n"
                . "it will not be found by somebody trying clinic names — do not publish\n"
                . "it. The queue link above it is meant to be shared: put it on the TV.\n\n"
            : "  The queue your patients watch — put this on the waiting-room TV\n"
                . "  or print it on a card:\n    {$boardUrl}\n\n"
                . "  Staff sign-in and everything you manage:\n    {$opsUrl}\n\n"
                . "Both are on this one address, because your clinic has no separate\n"
                . "subdomain yet. The queue link above is the one to share; staff sign\n"
                . "in at the address you already know and land on the dashboard. When\n"
                . "your own address is set up, we will send it to you.\n\n";

        $body = "Hello {$name},\n\n"
            . $opening . "\n\n"
            . "Two addresses, and they do different jobs:\n\n"
            . $addresses
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
