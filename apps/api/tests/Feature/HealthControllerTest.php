<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/__health', HealthController::class);
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
