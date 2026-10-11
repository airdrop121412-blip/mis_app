<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ChangePasswordRequest;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\PasswordResetService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Authentication + password lifecycle (architecture §2, §2.2, §2.3).
 * Login is token-based for now; SPA cookie flow is enabled at the middleware
 * layer and activated in Phase 5 (no schema change required).
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly PasswordResetService $passwordReset,
    ) {}

    /**
     * POST /api/v1/auth/login  (throttle:5,1)
     *
     * Returns a Sanctum plain-text token; the client must send it as
     * `Authorization: Bearer <token>` on subsequent requests.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = $this->auth->attempt($credentials['username'], $credentials['password']);

        if (! $user) {
            throw ValidationException::withMessages([
                'username' => ['These credentials do not match our records.'],
            ]);
        }

        $this->auth->forgetUserPermissions($user);

        $token = $user->createToken($request->header('User-Agent', 'api'))->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'user_id' => $user->user_id,
                'username' => $user->username,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'must_change_password' => $this->auth->mustChangePassword($user),
                'roles' => $user->roles()->pluck('role.code'),
            ],
        ], 'Login successful.');
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success([
            'user_id' => $user->user_id,
            'username' => $user->username,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'is_active' => $user->is_active,
            'must_change_password' => $this->auth->mustChangePassword($user),
            'roles' => $user->roles()->pluck('role.code'),
            'permissions' => $this->auth->userPermissions($user),
        ]);
    }

    /**
     * POST /api/v1/auth/change-password
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($request->validated('current_password'), $user->password_hash)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        if (Hash::check($request->validated('new_password'), $user->password_hash)) {
            throw ValidationException::withMessages([
                'new_password' => ['The new password must be different from the current password.'],
            ]);
        }

        $user->forceFill([
            'password_hash' => Hash::make($request->validated('new_password')),
        ])->save();

        $this->auth->markPasswordChanged($user);
        $this->auth->forgetUserPermissions($user);

        return ApiResponse::success(null, 'Password changed.');
    }

    /**
     * POST /api/v1/auth/logout — revoke the current bearer token.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->user()->currentAccessToken()?->delete();
        $this->auth->forgetUserPermissions($user);

        return ApiResponse::success(null, 'Logged out.');
    }

    // ── Password reset (architecture §2.3) ─────────────────────

    /**
     * POST /api/v1/auth/forgot-password  (throttle:5,1)
     *
     * Generates a reset token, attempts to email it, and always returns
     * a generic success message — never reveals whether the account exists.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $user = $this->passwordReset->resolveUser($request->validated('username'));

        if ($user) {
            $token = $this->passwordReset->generateToken($user);

            Mail::to($user->email)->send(new PasswordResetMail($token, $user->user_id));
        }

        // Always return success — no account enumeration.
        return ApiResponse::success(
            null,
            'If an account with that identifier exists, a reset token has been sent.',
        );
    }

    /**
     * POST /api/v1/auth/reset-password  (throttle:5,1)
     *
     * Validates the token, sets the new password, and consumes the token.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = $this->passwordReset->resolveUser($data['username']);

        if (! $user) {
            throw ValidationException::withMessages([
                'token' => ['The password reset token is invalid or has expired.'],
            ]);
        }

        try {
            $this->passwordReset->resetPassword($user, $data['token'], $data['password']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'token' => [$e->getMessage()],
            ]);
        }

        $this->auth->forgetUserPermissions($user);

        return ApiResponse::success(null, 'Password has been reset.');
    }
}
