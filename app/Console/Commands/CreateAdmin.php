<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Create (or reset) an application admin without touching the Phase 2/3
 * seed files. Provided for production bootstrapping (architecture §2.2):
 *
 *   php artisan app:create-admin
 *
 * Uses the role CODE (never a numeric id) and keeps first-login force-change
 * behaviour via the remarks marker.
 */
class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin
        {--username=admin}
        {--full-name=System Administrator}
        {--email=}
        {--mobile=}
        {--password=}
        {--no-force-change : Do not require a password change on first login}';

    protected $description = 'Create or reset the application administrator user (RBAC role ADMIN by code).';

    public function handle(): int
    {
        $username = (string) $this->option('username');
        $password = (string) $this->option('password');

        if ($password === '') {
            $password = (string) $this->secret('Password for the admin account');
        }

        if (strlen($password) < 12) {
            $this->error('Password must be at least 12 characters long.');

            return self::FAILURE;
        }

        $role = Role::query()->where('code', 'ADMIN')->first();
        if (! $role) {
            $this->error('Role ADMIN not found. Run Phase 3 seed (role table) first.');

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['username' => $username]);

        $user->full_name = (string) $this->option('full-name');
        $user->email = $this->option('email') ?: $user->email;
        $user->mobile = $this->option('mobile') ?: $user->mobile;
        $user->is_active = true;
        $user->password_hash = Hash::make($password, ['rounds' => 12]);
        $user->remarks = $this->option('no-force-change')
            ? str_replace('TEMP_PASSWORD_FORCE_CHANGE', '', (string) $user->remarks)
            : trim(($user->remarks ?? '').' TEMP_PASSWORD_FORCE_CHANGE');
        $user->save();

        $exists = UserRole::query()
            ->where('user_id', $user->user_id)
            ->where('role_id', $role->role_id)
            ->exists();

        if (! $exists) {
            UserRole::query()->create([
                'user_id' => $user->user_id,
                'role_id' => $role->role_id,
                'remarks' => 'assigned via app:create-admin',
            ]);
        }

        $this->info("Admin user '{$username}' (user_id={$user->user_id}) is ready with role ADMIN.");

        return self::SUCCESS;
    }
}
