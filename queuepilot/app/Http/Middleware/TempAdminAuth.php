<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Response;
use App\Core\Session;

/**
 * TempAdminAuth — TEMPORARY development-only middleware.
 *
 * Bypasses all authentication and signs the request in directly as the
 * platform administrator (super_admin). No password, no login screen, no
 * CSRF, no auth middleware required.
 *
 * INTENDED FOR LOCAL DEVELOPMENT AND TESTING ONLY. Remove before any
 * production deploy — this is a complete authentication bypass.
 */
final class TempAdminAuth
{
    public function handle(): void
    {
        // If already authenticated, leave the session alone.
        if (Auth::check()) {
            return;
        }

        // Look up the platform admin user directly from the database.
        $admin = Db::one(
            'SELECT id, name, email, role, clinic_id FROM users '
            . 'WHERE role = ? AND is_active = 1 LIMIT 1',
            ['super_admin']
        );

        if (!$admin) {
            // Fallback: create a minimal admin user if none exists.
            $adminId = Db::insert(
                'INSERT INTO users (clinic_id, role, name, email, phone, password_hash, locale, is_active, created_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, 1, datetime("now"))',
                [NULL, 'super_admin', 'Platform Admin', 'owner@queuepilot.app', NULL, '', 'en']
            );
            $admin = ['id' => $adminId, 'name' => 'Platform Admin', 'email' => 'owner@queuepilot.app', 'role' => 'super_admin', 'clinic_id' => NULL];
        }

        // Set the session directly — no password check, no 2FA, no login screen.
        Session::regenerate();
        Session::put('user_id', (int) $admin['id']);
        Session::put('clinic_id', $admin['clinic_id'] !== null ? (int) $admin['clinic_id'] : null);
        Session::put('started_at', \App\Core\Db::now());

        Auth::forgetBranch();

        Response::redirect('/admin');
    }
}
