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

test('storage check fails fast within the timeout budget when the s3 endpoint is a black hole', function () {
    config()->set('filesystems.default', 's3');
    config()->set('filesystems.disks.s3', [
        'driver' => 's3',
        'key' => 'health',
        'secret' => 'health',
        'region' => 'us-east-1',
        'bucket' => 'health-probe',
        'endpoint' => 'http://10.255.255.1:9000',
        'use_path_style_endpoint' => true,
        'retries' => 0,
        'throw' => false,
    ]);

    $start = microtime(true);
    $response = $this->getJson('/__health');
    $elapsed = microtime(true) - $start;

    $response->assertStatus(503)
        ->assertJsonPath('checks.storage', 'failed');

    expect($elapsed)->toBeLessThan(8.0);
})->group('storage-timeout');

test('exposes no hostnames, ports, versions, or connection details in the degraded response', function () {
    config()->set('database.redis.health.host', '127.0.0.1');
    config()->set('database.redis.health.port', 1);

    $degraded = $this->getJson('/__health')->assertStatus(503)->getContent();

    $secrets = ['127.0.0.1', '6379', 'phpredis', 'sqlite', 'pgsql', 'localhost', 'password'];

    foreach ($secrets as $secret) {
        expect($degraded)->not->toContain($secret);
    }
});
