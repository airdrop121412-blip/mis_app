<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state (maps to the authoritative `users`
     * table: user_id PK, password_hash, no timestamps).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'password_hash' => static::$password ??= Hash::make('password'),
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'mobile' => fake()->numerify('01#########'),
            'is_active' => true,
            'remarks' => null,
        ];
    }

    /**
     * Mark the user with the first-login force-change marker (dev/test).
     */
    public function forceChangeOnFirstLogin(): static
    {
        return $this->state(fn () => [
            'remarks' => 'DEV_TEST_ONLY|TEMP_PASSWORD_FORCE_CHANGE',
        ]);
    }
}
