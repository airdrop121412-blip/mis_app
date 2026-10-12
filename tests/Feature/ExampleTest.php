<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * API-only smoke test (replaces the Laravel default web `/` test, which no
 * longer applies: web routing was removed in PHASE 4A — see bootstrap/app.php).
 */
class ExampleTest extends TestCase
{
    public function test_health_endpoint_responds(): void
    {
        $this->getJson('/api/v1/health')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok');
    }
}
