<?php

test('GET /v1/health resolves to the health endpoint', function () {
    $response = $this->getJson('/v1/health');

    expect($response->status())->not->toBe(404);
    $response->assertOk()->assertJsonPath('status', 'ok');
});

test('the health endpoint is not exposed under an /api prefix', function () {
    $this->getJson('/api/v1/health')->assertNotFound();
});
