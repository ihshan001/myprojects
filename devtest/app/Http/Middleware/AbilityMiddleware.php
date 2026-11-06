<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * Ability middleware: ensures the authenticated user can perform an action.
 *
 * The ability string matches the keys in Auth::matrix(), e.g.
 * 'queue.operate', 'clinic.manage', 'reports.view'.
 *
 * Returns 403 (JSON or HTML) when the user lacks the ability.
 */
final class AbilityMiddleware
{
    /** @param string $ability */
    public function __construct(private string $ability) {}

    public function handle(): void
    {
        if (!Auth::can($this->ability)) {
            if (Request::wantsJson()) {
                Response::json([
                    'ok' => false,
                    'error' => 'forbidden',
                    'message' => 'You do not have permission to perform this action.',
                ], 403);
                return;
            }
            abort(403, 'You do not have permission to perform this action.');
        }
    }
}
