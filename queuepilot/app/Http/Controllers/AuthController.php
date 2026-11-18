<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Session;
use App\Core\Db;
use App\Services\AuditService;

final class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            redirect(Auth::isSuper() ? '/admin' : '/app');
            return;
        }
        view('auth/login', ['title' => 'Sign in', 'email' => ''], false);
    }

    public function login(): void
    {
        $email = Request::string('email');
        $password = (string) Request::input('password', '');

        if ($email === '' || $password === '') {
            flash('error', 'Enter your email and password.');
            Session::flash('old', ['email' => $email]);
            redirect('/login');
            return;
        }

        [$ok, , $reason] = Auth::attempt($email, $password, Request::ip());

        if ($ok && $reason === '2fa_required') {
            // Password accepted, session not yet established. The code prompt
            // is a separate page so a password manager's autofill never
            // submits an empty code as the second factor.
            redirect('/login/2fa');
            return;
        }

        if (!$ok) {
            flash('error', $reason === 'too_many'
                ? 'Too many attempts. Please wait a few minutes before trying again.'
                : 'Those details did not match an active account.');
            Session::flash('old', ['email' => $email]);
            redirect('/login');
            return;
        }

        $intended = Session::takeFlash('intended');
        $target = is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//')
            ? $intended
            : self::homeFor((string) Auth::role());

        $u = Auth::user();
        flash('success', 'Welcome back, ' . explode(' ', (string) $u['name'])[0] . '.');
        redirect($target);
    }

    /**
     * Where a role should land after signing in.
     *
     * A doctor belongs in their own workspace, not on the reception board —
     * the board is built around issuing tokens, which is the one thing a
     * doctor cannot do. Sending them there first thing means their first
     * screen is full of controls they are not allowed to use.
     */
    private static function homeFor(string $role): string
    {
        return match ($role) {
            Auth::ROLE_DOCTOR => '/doctor',
            Auth::ROLE_ADMIN, 'super_admin' => Auth::isSuper() ? '/admin' : '/app',
            default => '/app',
        };
    }

    public function logout(): void
    {
        Auth::logout();
        Session::start();
        Csrf::rotate();
        flash('success', 'You have been signed out.');
        redirect('/login');
    }

    public function locale(): void
    {
        $locale = Request::string('locale', 'en');
        $locale = in_array($locale, ['en', 'si', 'ta'], true) ? $locale : 'en';
        Session::put('locale', $locale);
        Lang::load($locale);
        back('/app');
    }

    /* ------------------------------------------------------------------ */
    /* Two-factor step-up                                                  */
    /* ------------------------------------------------------------------ */

    public function showTwoFactor(): void
    {
        $pending = Auth::pendingTwoFactorId();
        if ($pending === null) {
            flash('error', 'Sign in again to continue.');
            redirect('/login');
            return;
        }
        view('auth/two_factor', ['title' => 'Two-factor code', 'error' => null]);
    }

    public function submitTwoFactor(): void
    {
        $pending = Auth::pendingTwoFactorId();
        if ($pending === null) {
            flash('error', 'That took too long. Sign in again.');
            redirect('/login');
            return;
        }

        $code = trim(Request::string('code'));
        if ($code === '') {
            view('auth/two_factor', ['title' => 'Two-factor code', 'error' => 'Enter the 6-digit code from your authenticator app.']);
            return;
        }

        if (!\App\Services\TotpService::verifyForUser($pending, $code)) {
            (new AuditService())->log(null, 'auth.2fa_failed', 'user', $pending, ['ip' => Request::ip()]);
            view('auth/two_factor', [
                'title' => 'Two-factor code',
                'error' => 'That code did not match. Codes change every 30 seconds — try the current one.',
            ]);
            return;
        }

        $user = Db::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$pending]);
        if (!$user) {
            Auth::clearTwoFactorPending();
            flash('error', 'That account is no longer active.');
            redirect('/login');
            return;
        }

        Auth::completeLogin($user, Request::ip());
        (new AuditService())->log((int) ($user['clinic_id'] ?? 0) ?: null, 'auth.2fa_passed', 'user', (int) $user['id'], []);
        $intended = Session::takeFlash('intended');
        $target = is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//')
            ? $intended
            : self::homeFor((string) $user['role']);
        flash('success', 'Welcome back, ' . explode(' ', (string) $user['name'])[0] . '.');
        redirect($target);
    }

    public function cancelTwoFactor(): void
    {
        Auth::clearTwoFactorPending();
        flash('success', 'Sign-in cancelled.');
        redirect('/login');
    }

    /* ------------------------------------------------------------------ */
    /* Invitation acceptance                                               */
    /* ------------------------------------------------------------------ */

    public function showInvite(string $token): void
    {
        $service = new \App\Services\StaffInviteService();
        $invite = $service->find($token);
        if (!$invite) {
            view('auth/invite', [
                'title' => 'Invitation',
                'invalid' => 'This invitation is invalid, already used, or has expired. Ask your clinic administrator for a new one.',
                'token' => $token,
            ], false);
            return;
        }
        view('auth/invite', [
            'title' => 'Join ' . $invite['clinic_name'],
            'invite' => $invite,
            'token' => $token,
            'name' => '',
            'error' => null,
            'passwordRule' => \App\Services\StaffInviteService::passwordRule(),
        ], false);
    }

    public function acceptInvite(string $token): void
    {
        $service = new \App\Services\StaffInviteService();
        $invite = $service->find($token);
        if (!$invite) {
            view('auth/invite', [
                'title' => 'Invitation',
                'invalid' => 'This invitation is invalid, already used, or has expired. Ask your clinic administrator for a new one.',
                'token' => $token,
            ], false);
            return;
        }

        $name = trim(Request::string('name'));
        $password = (string) Request::input('password', '');
        $confirm = (string) Request::input('password_confirm', '');

        $error = null;
        if ($password !== $confirm) {
            $error = 'The two passwords do not match.';
        } else {
            $result = $service->accept($token, $name, $password);
            if (!$result['ok']) {
                $error = $result['error'];
            }
        }

        if ($error !== null) {
            http_response_code(422);
            view('auth/invite', [
                'title' => 'Join ' . $invite['clinic_name'],
                'invite' => $invite,
                'token' => $token,
                'name' => $name,
                'error' => $error,
                'passwordRule' => \App\Services\StaffInviteService::passwordRule(),
            ], false);
            return;
        }

        flash('success', 'Your account is ready. Sign in with your new password.');
        redirect('/login');
    }

    /* ------------------------------------------------------------------ */
    /* Account security (2FA enrolment)                                    */
    /* ------------------------------------------------------------------ */

    public function security(): void
    {
        $userId = (int) Auth::id();
        view('settings/security', [
            'title' => 'Account security',
            'enabled' => \App\Services\TotpService::isEnabled($userId),
            'pending' => Session::takeFlash('totp_enrolment'),
            'recovery' => Session::takeFlash('totp_recovery'),
            'error' => Session::takeFlash('totp_error'),
        ]);
    }

    /**
     * Your own name, phone and language.
     *
     * The Starter plan is deliberately one doctor with no "add doctor"
     * capability, which makes this page the only place a solo owner can put
     * their own name into the system. Without it the doctor is whoever the
     * signup form said, for ever.
     *
     * Email is not editable here: it is the sign-in identity, and letting
     * somebody change it from an already-signed-in session would move the
     * account without ever proving they own the new address.
     */
    public function showProfile(): void
    {
        view('settings/profile', [
            'title'   => 'Your details',
            'user'    => Auth::user(),
            'locales' => (array) \App\Core\Env::get('app.locales', ['en' => 'English']),
            'errors'  => Session::takeFlash('profile_errors') ?? [],
        ]);
    }

    public function saveProfile(): void
    {
        $user = Auth::user();
        $name = trim((string) Request::input('name', ''));
        $phone = trim((string) Request::input('phone', ''));
        $locale = (string) Request::input('locale', 'en');

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Your name cannot be empty — it is what a patient hears when you call them.';
        } elseif (mb_strlen($name) > 120) {
            $errors['name'] = 'That is longer than 120 characters.';
        }
        $allowed = array_keys((array) \App\Core\Env::get('app.locales', ['en' => 'English']));
        if (!in_array($locale, $allowed, true)) {
            $errors['locale'] = 'That is not one of the languages this install offers.';
        }

        if ($errors !== []) {
            Session::flash('profile_errors', $errors);
            redirect('/settings/profile');
        }

        \App\Core\Db::run(
            'UPDATE users SET name = ?, phone = ?, locale = ? WHERE id = ?',
            [$name, $phone !== '' ? $phone : null, $locale, (int) $user['id']]
        );

        // A doctor's own row on the doctor list is what the queue board reads,
        // so a name that only changed on the login record would still be read
        // out wrong to every patient.
        if (($user['role'] ?? '') === 'doctor' && !empty($user['doctor_id'])) {
            \App\Core\Db::run('UPDATE doctors SET name = ? WHERE id = ?', [$name, (int) $user['doctor_id']]);
        }

        (new \App\Services\AuditService())->log(
            (int) ($user['clinic_id'] ?? 0),
            'user.profile_updated',
            'user',
            (int) $user['id'],
            ['name' => $name, 'phone_set' => $phone !== '', 'locale' => $locale]
        );

        flash('success', 'Your details have been saved.');
        redirect('/settings/profile');
    }

    /**
     * Change your own password.
     *
     * Reached two ways: voluntarily from the security page, and forcibly on
     * first sign-in when an admin created the account with a temporary
     * password. The second case is why must_change_password exists at all — a
     * password an administrator chose and read out over the phone must stop
     * being the password after it has been used once.
     */
    public function changePassword(): void
    {
        $user = Auth::user();
        $current = (string) Request::input('current_password', '');
        $new     = (string) Request::input('password', '');
        $confirm = (string) Request::input('password_confirmation', '');

        $errors = [];

        // The current password is required even when changing a temporary one:
        // otherwise a stolen unlocked laptop is a permanent account takeover.
        if ($current === '' || !password_verify($current, (string) $user['password_hash'])) {
            $errors['current_password'] = 'That is not your current password.';
        }

        $min = (int) \App\Core\Env::get('security.password_min', 10);
        if (strlen($new) < $min) {
            $errors['password'] = "Use at least {$min} characters.";
        } elseif ($new !== $confirm) {
            $errors['password'] = 'The two passwords do not match.';
        } elseif (password_verify($new, (string) $user['password_hash'])) {
            $errors['password'] = 'That is your current password. Choose a different one.';
        }

        if ($errors !== []) {
            Session::flash('password_errors', $errors);
            redirect('/settings/password');
        }

        \App\Core\Db::run(
            'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), (int) $user['id']]
        );
        (new \App\Services\AuditService())->log(
            (int) $user['clinic_id'],
            'user.password_changed',
            'user',
            (int) $user['id'],
            ['self' => true]
        );
        flash('success', 'Your password has been changed.');
        redirect('/settings');
    }

    /** The page with the form. */
    public function showChangePassword(): void
    {
        view('settings/password', [
            'title'     => 'Change password',
            'errors'    => Session::takeFlash('password_errors') ?? [],
            'forced'    => (bool) (Auth::user()['must_change_password'] ?? false),
        ]);
    }

    public function beginTwoFactor(): void
    {
        $result = \App\Services\TotpService::beginEnrolment((int) Auth::id());
        if (!$result['ok']) {
            Session::flash('totp_error', $result['error']);
        } else {
            Session::flash('totp_enrolment', $result['uri']);
        }
        redirect('/settings/security');
    }

    public function confirmTwoFactor(): void
    {
        $result = \App\Services\TotpService::enable((int) Auth::id(), trim(Request::string('code')));
        if (!$result['ok']) {
            Session::flash('totp_error', $result['error']);
        } else {
            Session::flash('totp_recovery', $result['recovery_codes']);
            flash('success', 'Two-factor authentication is on. Store your recovery codes somewhere safe.');
        }
        redirect('/settings/security');
    }

    public function disableTwoFactor(): void
    {
        $result = \App\Services\TotpService::disable((int) Auth::id(), (string) Request::input('password', ''));
        if (!$result['ok']) {
            Session::flash('totp_error', $result['error']);
        } else {
            flash('success', 'Two-factor authentication is off for your account.');
        }
        redirect('/settings/security');
    }
}
