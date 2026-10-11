<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Cache-based password-reset token management (architecture §2.3).
 *
 * Tokens are stored in the application cache (file or Redis) with a 30-minute
 * TTL. The plain-text token is returned to the caller (for emailing); only the
 * sha-256 hash is stored in cache. This avoids a `password_resets` schema
 * change as documented in the architecture trade-off.
 */
class PasswordResetService
{
    private const CACHE_PREFIX = 'password_reset:';

    private const TOKEN_TTL = 1800; // 30 minutes in seconds

    /**
     * Generate a new reset token for the given user.
     *
     * Returns the plain-text token (to be emailed). Stores the sha-256 hash
     * in cache with a 30-minute TTL. Any previous unused token is overwritten.
     */
    public function generateToken(User $user): string
    {
        $plainToken = Str::random(60);
        $hashedToken = hash('sha256', $plainToken);

        Cache::put(
            self::CACHE_PREFIX.$user->user_id,
            $hashedToken,
            self::TOKEN_TTL,
        );

        return $plainToken;
    }

    /**
     * Validate a reset token against the cached hash.
     *
     * Returns true if the token matches and has not expired.
     * Returns false if the token is invalid, expired, or already consumed.
     */
    public function validateToken(User $user, string $plainToken): bool
    {
        $cachedHash = Cache::get(self::CACHE_PREFIX.$user->user_id);

        if ($cachedHash === null) {
            return false; // Expired or never generated
        }

        return hash_equals($cachedHash, hash('sha256', $plainToken));
    }

    /**
     * Consume (delete) the reset token after successful use.
     */
    public function consumeToken(User $user): void
    {
        Cache::forget(self::CACHE_PREFIX.$user->user_id);
    }

    /**
     * Full password-reset flow: validate token → update password → consume token.
     *
     * Throws \InvalidArgumentException if the token is invalid.
     */
    public function resetPassword(User $user, string $plainToken, string $newPassword): void
    {
        if (! $this->validateToken($user, $plainToken)) {
            throw new \InvalidArgumentException(
                'The password reset token is invalid or has expired.',
            );
        }

        $user->forceFill([
            'password_hash' => Hash::make($newPassword),
        ])->save();

        $this->consumeToken($user);
    }

    /**
     * Look up a user by username or email (for the forgot-password flow).
     *
     * Returns null if no matching active user is found — the controller
     * must NOT distinguish this from a found user (no account enumeration).
     */
    public function resolveUser(string $identifier): ?User
    {
        return User::query()
            ->where('username', $identifier)
            ->orWhere('email', $identifier)
            ->where('is_active', true)
            ->first();
    }
}
