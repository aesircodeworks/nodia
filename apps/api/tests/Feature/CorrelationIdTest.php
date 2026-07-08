<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/__correlation-probe', fn () => response()->noContent());
});

test('echoes a client-provided X-Correlation-Id header on the response', function () {
    $response = $this->withHeader('X-Correlation-Id', 'client-provided-id')
        ->get('/__correlation-probe');

    $response->assertNoContent();

    expect($response->headers->get('X-Correlation-Id'))->toBe('client-provided-id');
});

test('generates a UUIDv7 correlation id and echoes it when the header is absent', function () {
    $response = $this->get('/__correlation-probe');

    $response->assertNoContent();

    expect($response->headers->get('X-Correlation-Id'))
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

test('logs exactly one request line carrying the correlation id in the log context', function () {
    $stream = tempnam(sys_get_temp_dir(), 'correlation-stdout-');

    config()->set('logging.channels.stdout.handler_with.stream', $stream);

    $this->withHeader('X-Correlation-Id', 'ctx-correlation-id')
        ->get('/__correlation-probe')
        ->assertNoContent();

    $lines = array_values(array_filter(explode("\n", file_get_contents($stream))));

    expect($lines)->toHaveCount(1);

    $line = json_decode($lines[0], true);

    expect($line)->not->toBeNull()
        ->and($line['context']['correlation_id'])->toBe('ctx-correlation-id')
        ->and($line['context']['method'])->toBe('GET')
        ->and($line['context']['path'])->toBe('/__correlation-probe')
        ->and($line['context']['status'])->toBe(204)
        ->and($line['context'])->toHaveKey('duration_ms');

    unlink($stream);
});
