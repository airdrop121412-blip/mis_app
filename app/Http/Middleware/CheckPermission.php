<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * permission:<code>  — gate a route by a permission code resolved from the
 * user's cache-backed RBAC matrix (architecture §3). Example:
 *
 *     Route::middleware('permission:ep.view')->get(...)
 */
class CheckPermission
{
    public function __construct(private readonly AuthService $auth) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user || ! $this->auth->hasPermission($user, $permission)) {
            abort(403, 'Forbidden.');
        }

        return $next($request);
    }
}
