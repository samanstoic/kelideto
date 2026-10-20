<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
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
                'timestamp',
                'services' => [
                    'database' => ['status'],
                    'cache' => ['status'],
                ],
            ]);

        $this->assertEquals('healthy', $response->json('status'));
        $this->assertEquals('healthy', $response->json('services.database.status'));
        $this->assertEquals('healthy', $response->json('services.cache.status'));
        $response->assertJsonMissingPath('environment')
            ->assertJsonMissingPath('services.database.driver');
    }

    public function test_health_check_does_not_disclose_dependency_exception_details(): void
    {
        Cache::shouldReceive('put')
            ->once()
            ->andThrow(new \RuntimeException('sensitive cache connection details'));

        $this->getJson('/api/health')
            ->assertServiceUnavailable()
            ->assertJsonPath('services.cache.status', 'unhealthy')
            ->assertJsonMissing(['error' => 'sensitive cache connection details'])
            ->assertDontSeeText('sensitive cache connection details');
    }
}
