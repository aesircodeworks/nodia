<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/__health', HealthController::class);
});

test('returns 200 with the health report contract shape when every dependency is reachable', function () {
    $response = $this->getJson('/__health');

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

test('returns 503 problem+json naming the failed dependency when one is unreachable', function () {
    config()->set('database.redis.health.host', '127.0.0.1');
    config()->set('database.redis.health.port', 1);

    $response = $this->getJson('/__health');

    $response->assertStatus(503)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'health.degraded')
        ->assertJsonPath('status', 503)
        ->assertJsonPath('checks.database', 'ok')
        ->assertJsonPath('checks.redis', 'failed')
        ->assertJsonPath('checks.storage', 'ok');

    expect($response->json('checked_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($response->json())->toHaveKeys(['type', 'title', 'detail']);
});

test('exposes no hostnames, ports, versions, or connection details in the degraded response', function () {
    config()->set('database.redis.health.host', '127.0.0.1');
    config()->set('database.redis.health.port', 1);

    $degraded = $this->getJson('/__health')->assertStatus(503)->getContent();

    $secrets = ['127.0.0.1', '6379', 'phpredis', 'sqlite', 'pgsql', 'localhost', 'password'];

    foreach ($secrets as $secret) {
        expect($degraded)->not->toContain($secret);
    }
});
