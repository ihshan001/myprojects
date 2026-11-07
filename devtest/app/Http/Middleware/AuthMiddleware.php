<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Authentication middleware: ensures the request has an authenticated user.
 *
 * Used on routes that require a logged-in clinic or platform user.
 * DEVTEST: identity is pinned per port, so this only trips on an unseeded
 * database. Returns 401 (JSON) or redirects to the workspace root (HTML).
 */
final class AuthMiddleware
{
    public function handle(): void
    {
        if (!Auth::check()) {
            if (Request::wantsJson()) {
                Response::json(['ok' => false, 'error' => 'unauthenticated'], 401);
                return;
            }
            Response::redirect('/');
        }
    }
}
