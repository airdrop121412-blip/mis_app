<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Project CRUD endpoints (architecture §5.3).
 * Uses DatabaseTransactions — never RefreshDatabase.
 */
class ProjectTest extends TestCase
{
    use DatabaseTransactions;

    private function adminToken(): string
    {
        $user = User::factory()->create([
            'username' => 'proj_admin_'.fake()->unique()->numerify('#####'),
            'password_hash' => bcrypt('SecretPass@123'),
        ]);

        DB::table('user_role')->insert([
            'user_id' => $user->user_id,
            'role_id' => 1, // ADMIN
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'SecretPass@123',
        ]);

        return $response->json('data.token');
    }

    // ── READ (master.view) ────────────────────────────────────────

    public function test_list_projects_returns_paginated(): void
    {
        $token = $this->adminToken();

        // Create a project so the list is non-empty.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/projects', [
                'project_code' => 'LIST_PROJ',
                'name' => 'List Test Project',
            ])->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/projects');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [0 => ['project_id', 'project_code', 'name']],
                'meta' => ['pagination' => ['current_page', 'total']],
            ]);
    }

    public function test_create_then_show_project(): void
    {
        $token = $this->adminToken();

        $create = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/projects', [
                'project_code' => 'PRJ_TEST_'.fake()->unique()->numerify('###'),
                'name' => 'Test Project '.fake()->unique()->numerify('###'),
                'description' => 'A test project',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
            ]);

        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.project_code', $create->json('data.project_code'))
            ->assertHeader('Location');

        $id = $create->json('data.project_id');

        $show = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/projects/{$id}");

        $show->assertOk()
            ->assertJsonPath('data.name', $create->json('data.name'))
            ->assertJsonPath('data.description', 'A test project')
            ->assertJsonPath('data.start_date', '2026-01-01');
    }

    public function test_list_with_search_filter(): void
    {
        $token = $this->adminToken();

        // Create a project with a unique name, then search for it.
        $name = 'Searchable '.fake()->unique()->numerify('#####');
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/projects', [
                'project_code' => 'SRCH_'.fake()->unique()->numerify('#####'),
                'name' => $name,
            ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/projects?q='.urlencode(substr($name, 0, 6)));

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    // ── CREATE (master.manage) ────────────────────────────────────

    public function test_create_project(): void
    {
        $token = $this->adminToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/projects', [
                'project_code' => 'NEW_PROJ',
                'name' => 'New Project',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.project_code', 'NEW_PROJ')
            ->assertJsonPath('data.name', 'New Project');
    }

    public function test_create_rejects_duplicate_code(): void
    {
        $token = $this->adminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/projects', [
                'project_code' => 'DUP_CODE',
                'name' => 'First',
            ])->assertCreated();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/projects', [
                'project_code' => 'DUP_CODE',
                'name' => 'Second',
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['project_code']);
    }

    public function test_create_rejects_duplicate_name(): void
    {
        $token = $this->adminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/projects', [
                'project_code' => 'CODE_A',
                'name' => 'Same Name',
            ])->assertCreated();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/projects', [
                'project_code' => 'CODE_B',
                'name' => 'Same Name',
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_create_requires_code_and_name(): void
    {
        $token = $this->adminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/projects', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_code', 'name']);
    }

    // ── UPDATE (master.manage) ────────────────────────────────────

    public function test_update_project(): void
    {
        $token = $this->adminToken();

        $create = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/projects', [
                'project_code' => 'UPD_PROJ',
                'name' => 'Original Name',
            ]);
        $id = $create->json('data.project_id');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/projects/{$id}", [
                'name' => 'Updated Name',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.project_code', 'UPD_PROJ');
    }

    public function test_update_rejects_code_collision(): void
    {
        $token = $this->adminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/projects', [
                'project_code' => 'PROJ_A',
                'name' => 'Project A',
            ]);

        $create2 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/projects', [
                'project_code' => 'PROJ_B',
                'name' => 'Project B',
            ]);

        $id2 = $create2->json('data.project_id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/projects/{$id2}", [
                'project_code' => 'PROJ_A', // Taken
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_code']);
    }

    // ── PERMISSION GATING ─────────────────────────────────────────

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/projects')->assertStatus(401);
    }

    public function test_user_without_permission_cannot_create_project(): void
    {
        $user = User::factory()->create([
            'username' => 'proj_noperm_'.fake()->unique()->numerify('#####'),
            'password_hash' => bcrypt('SecretPass@123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'SecretPass@123',
        ]);
        $token = $response->json('data.token');

        // GET list is intentionally open to any authenticated user (the project
        // picker — routes/api/v1.php §Projects), so a role-less user may read it.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/projects')
            ->assertOk();

        // Create/update/delete require master.manage — a user with no roles
        // must NOT be able to create a project.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/projects', [
                'project_code' => 'NOPERM_'.fake()->unique()->numerify('#####'),
                'name' => 'No Permission Project '.fake()->unique()->numerify('#####'),
            ])
            ->assertStatus(403);

        // Update is equally gated (only asserted when a project exists, since
        // route-model binding would otherwise 404 before the middleware).
        $existingId = DB::table('project')->orderBy('project_id')->value('project_id');
        if ($existingId !== null) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->putJson("/api/v1/projects/{$existingId}", ['name' => 'Hacked'])
                ->assertStatus(403);
        }
    }
}
