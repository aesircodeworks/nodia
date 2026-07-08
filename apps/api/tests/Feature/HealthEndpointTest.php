<?php

// Shapes are pinned against the /v1/health contract in
// specs/001-project-foundation/contracts/v1-health.openapi.yaml. assertExactJson
// enforces exactly the schema's required keys (additionalProperties: false), so
// the tests fail if the response drifts from the contract.

test('GET /v1/health returns 200 with a body matching the HealthReport contract shape', function () {
    $response = $this->getJson('/v1/health');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson([
            'status' => 'ok',
            'checks' => [
                'database' => 'ok',
                'redis' => 'ok',
                'storage' => 'ok',
            ],
            'checked_at' => $response->json('checked_at'),
        ]);

    expect($response->json('checked_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

test('GET /v1/health returns a 503 problem+json with code health.degraded naming the failed dependency', function () {
    config()->set('database.redis.health.host', '127.0.0.1');
    config()->set('database.redis.health.port', 1);

    $response = $this->getJson('/v1/health');

    $response->assertStatus(503)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'health.degraded')
        ->assertJsonPath('status', 503)
        ->assertJsonPath('checks.redis', 'failed')
        ->assertJsonPath('checks.database', 'ok')
        ->assertJsonPath('checks.storage', 'ok');

    expect($response->json())->toHaveKeys(['type', 'title', 'detail', 'code', 'checks', 'checked_at'])
        ->and($response->json('checked_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

test('GET /v1/health echoes a client-provided X-Correlation-Id', function () {
    $response = $this->withHeader('X-Correlation-Id', 'health-correlation-id')
        ->getJson('/v1/health');

    $response->assertOk();

    expect($response->headers->get('X-Correlation-Id'))->toBe('health-correlation-id');
});

test('GET /v1/health generates and echoes a UUIDv7 X-Correlation-Id when none is sent', function () {
    $response = $this->getJson('/v1/health');

    $response->assertOk();

    expect($response->headers->get('X-Correlation-Id'))
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});
