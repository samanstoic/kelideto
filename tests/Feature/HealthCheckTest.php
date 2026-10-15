<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    /**
     * Test genuine /api/health endpoint returns 200 and accurate service status.
     */
    public function test_health_check_endpoint_returns_healthy_status(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'app',
                'environment',
                'timestamp',
                'services' => [
                    'database' => ['status', 'driver'],
                    'cache' => ['status'],
                ],
            ]);

        $this->assertEquals('healthy', $response->json('status'));
        $this->assertEquals('healthy', $response->json('services.database.status'));
        $this->assertEquals('healthy', $response->json('services.cache.status'));
    }
}
