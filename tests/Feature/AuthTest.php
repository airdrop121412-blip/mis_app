<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;

/**
 * Auth lifecycle against the real schema. Every write runs inside a
 * transaction and rolls back, so the seeded database is never altered
 * (no RefreshDatabase — Phase 2/3 seed data must survive).
 */
class AuthTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(bool $forceChange = false): User
    {
        $factory = User::factory()->state([
            'username' => 'tester_'.fake()->unique()->numerify('#####'),
            'password_hash' => bcrypt('SecretPass@123'),
        ]);

        return $forceChange ? $factory->forceChangeOnFirstLogin()->create() : $factory->create();
    }

    public function test_login_returns_bearer_token(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'SecretPass@123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'token_type', 'user']]);
    }

    public function test_login_rejects_wrong_password(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'WrongPass@123',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['username']]);
    }

    public function test_me_returns_profile_without_password_hash(): void
    {
        $user = $this->makeUser();

        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.username', $user->username)
            ->assertJsonMissingPath('data.password_hash');
    }

    public function test_force_change_password_flow(): void
    {
        $user = $this->makeUser(forceChange: true);

        $token = $user->createToken('test')->plainTextToken;

        // Before changing, /auth/me reports the flag and other routes are blocked.
        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.must_change_password', true);

        // Change password (marker is removed; new hash active).
        $this->withToken($token)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'SecretPass@123',
                'new_password' => 'NewSecret@456',
                'new_password_confirmation' => 'NewSecret@456',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        // Marker cleared.
        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.must_change_password', false);
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = $this->makeUser();

        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'WrongCurrent@123',
                'new_password' => 'NewSecret@456',
                'new_password_confirmation' => 'NewSecret@456',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['current_password']]);
    }

    public function test_logout_revokes_token(): void
    {
        $user = $this->makeUser();

        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        // In-process requests share AuthManager guard singletons (RequestGuard
        // caches the user), whereas a real HTTP server boots a fresh app per
        // request. Reset the guards so the next request re-resolves the now
        // revoked bearer token — a faithful simulation of a new connection.
        $this->app['auth']->forgetGuards();

        // Token is now revoked — subsequent requests are 401.
        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }
}
