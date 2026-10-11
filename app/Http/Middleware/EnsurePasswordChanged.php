<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * force.password.change — while the current user still carries the
 * TEMP_PASSWORD_FORCE_CHANGE marker, only change-password / me / logout are
 * reachable; everything else returns 403 FORCE_PASSWORD_CHANGE_REQUIRED
 * (architecture §2.2, §2.3).
 */
class EnsurePasswordChanged
{
    public function __construct(private readonly AuthService $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $this->auth->mustChangePassword($user)) {
            // Route names are registered WITHOUT the api/v1 prefix (the prefix
            // only affects the URL). These three remain reachable while forced.
            $allowed = [
                'auth.change-password',
                'auth.me',
                'auth.logout',
            ];

            if (! in_array($request->route()?->getName(), $allowed, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You must change your password before continuing.',
                    'data' => null,
                    'meta' => null,
                    'errors' => ['code' => ['FORCE_PASSWORD_CHANGE_REQUIRED']],
                ], 403);
            }
        }

        return $next($request);
    }
}
