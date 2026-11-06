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
 * Returns 401 (JSON) or redirects to /login (HTML) when not authenticated.
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
            Session::flash('intended', $_SERVER['REQUEST_URI'] ?? '/');
            Response::redirect('/login');
        }
    }
}
