<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Env;
use App\Core\Response;
use App\Core\Session;

/**
 * Temporary dev-only role picker: enter any dashboard directly, no password.
 *
 * SECURITY: This controller is GATED behind APP_ENV !== 'production'. It
 * returns 404 in any production environment so it can never become a
 * production access bypass, even if the routes are accidentally deployed.
 *
 * Intended for testing every role's screens before real login is re-added.
 * Never for production.
 */
final class DevController
{
    /**
     * Abort unless the app is explicitly running in a non-production env.
     *
     * Default-deny: when APP_ENV is missing, empty, or unrecognised, the
     * dev picker is blocked. Only an explicit development/staging value
     * enables it. This ensures a misconfigured or missing env file can
     * never accidentally expose the role picker in production.
     */
    private function requireDev(): void
    {
        $env = strtolower((string) (Env::get('app.env') ?: getenv('APP_ENV') ?: 'production'));
        $allowed = ['development', 'testing', 'staging', 'local', 'dev'];
        if (!in_array($env, $allowed, true)) {
            abort(404, 'Not found.');
        }
    }

    public function chooseRole(): void
    {
        $this->requireDev();

        $users = Db::all(
            'SELECT id, name, email, role, clinic_id FROM users WHERE is_active = 1 ORDER BY role, name'
        );

        view('dev/choose_role', [
            'title' => 'Choose a role (dev access)',
            'users' => $users,
        ]);
    }

    /** Sign straight in as the given user id, no password. Dev only. */
    public function signInAs(string $id): void
    {
        $this->requireDev();

        $user = Db::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $id]);
        if (!$user) {
            abort(404, 'That user does not exist.');
        }

        Session::regenerate();
        Session::put('user_id', (int) $user['id']);
        Session::put('clinic_id', $user['clinic_id'] !== null ? (int) $user['clinic_id'] : null);
        Session::put('started_at', Db::now());

        Auth::forgetBranch();

        $target = $user['role'] === 'super_admin' ? '/admin' : '/app';
        redirect($target);
    }

    /** Leave the dev session and return to the role picker. Dev only. */
    public function signOut(): void
    {
        $this->requireDev();

        Session::destroy();
        redirect('/dev/role');
    }
}
