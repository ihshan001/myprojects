<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Response;
use App\Core\Session;

/**
 * Dev role picker: enter any dashboard directly, no password.
 *
 * SECURITY NOTE: This is a development convenience only. It bypasses all
 * authentication and authorization. It is intended for local testing and
 * should never be deployed to a production environment.
 *
 * Routes:
 *   GET  /dev/role              - Choose a role tile
 *   GET  /dev/role/sign-in-as/{id} - Sign in as that user, no password
 *   GET  /dev/role/sign-out     - Clear dev session, return to picker
 */
final class DevController
{
    public function chooseRole(): void
    {
        $users = Db::all(
            'SELECT id, name, email, role, clinic_id FROM users WHERE is_active = 1 ORDER BY role, name'
        );

        view('dev/choose_role', [
            'title' => 'Choose a role (dev access)',
            'users' => $users,
        ]);
    }

    /** Sign straight in as the given user id, no password, no checks. */
    public function signInAs(string $id): void
    {
        $user = Db::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $id]);
        if (!$user) {
            abort(404, 'That user does not exist.');
        }

        $this->establishSession($user);
    }

    /** Sign straight in as the first active user with the given role, no password. */
    public function goRole(string $role): void
    {
        $user = Db::one(
            'SELECT * FROM users WHERE role = ? AND is_active = 1 ORDER BY id LIMIT 1',
            [$role]
        );
        if (!$user) {
            abort(404, "No active user with role '$role'.");
        }

        $this->establishSession($user);
    }

    /** Shared session setup + redirect by role. */
    private function establishSession(array $user): void
    {
        Session::regenerate();
        Session::put('user_id', (int) $user['id']);
        Session::put('clinic_id', $user['clinic_id'] !== null ? (int) $user['clinic_id'] : null);
        Session::put('started_at', Db::now());

        Auth::forgetBranch();

        $target = $user['role'] === 'super_admin' ? '/admin' : '/app';
        redirect($target);
    }

    /** Clear the dev session and return to the role picker. */
    public function signOut(): void
    {
        Session::destroy();
        redirect('/dev/role');
    }
}
