<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA (cookie-session) support for the React web app; Bearer
        // tokens still work for external/mobile clients (architecture §2.1).
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'permission' => CheckPermission::class,
            'force.password.change' => EnsurePasswordChanged::class,
        ]);

        // API requests must raise AuthenticationException so the JSON
        // exception renderer below can return a 401 response. Redirecting
        // them to a web login route causes a 500 because this API has no
        // named `login` route.
        $middleware->redirectGuestsTo(function (Request $request): ?string {
            return $request->is('api/*') || $request->expectsJson()
                ? null
                : '/login';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API-first: render JSON for API paths or JSON-expecting requests.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            return $request->is('api/*') || $request->expectsJson()
                ? ApiResponse::error('Validation failed.', 'VALIDATION_FAILED', 422, $e->errors())
                : null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return $request->is('api/*') || $request->expectsJson()
                ? ApiResponse::error('Unauthenticated.', 'UNAUTHENTICATED', 401)
                : null;
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            return $request->is('api/*') || $request->expectsJson()
                ? ApiResponse::error('Forbidden.', 'FORBIDDEN', 403)
                : null;
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            return $request->is('api/*') || $request->expectsJson()
                ? ApiResponse::error('Resource not found.', 'NOT_FOUND', 404)
                : null;
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return $request->is('api/*') || $request->expectsJson()
                ? ApiResponse::error('Endpoint not found.', 'NOT_FOUND', 404)
                : null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            return $request->is('api/*') || $request->expectsJson()
                ? ApiResponse::error('Too many requests.', 'TOO_MANY_REQUESTS', 429)
                : null;
        });

        $exceptions->render(function (BusinessRuleException $e, Request $request) {
            return $request->is('api/*') || $request->expectsJson()
                ? ApiResponse::error($e->getMessage(), $e->getErrorCode(), $e->status)
                : null;
        });

        // Anything unhandled keeps Laravel's default 500 handling — the full
        // detail goes to the log, never to the client (architecture §20).
    })->create();
