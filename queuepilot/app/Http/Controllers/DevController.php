<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Session;

/**
 * Temporary dev-only access: pick a role and enter that dashboard directly,
 * no password. Intended for testing every role's screens before login is
 * re-added. Not for production — there is no password check here at all.
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

    /** Sign straight in as the given user id, no password. */
    public function signInAs(string $id): void
    {
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

    /** Leave the dev session and return to the role picker. */
    public function signOut(): void
    {
        Session::destroy();
        Response::redirect('/dev/role');
    }
}
