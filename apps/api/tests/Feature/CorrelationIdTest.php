<?php

use App\Support\Correlation\CorrelationId;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/__correlation-probe', fn () => response()->noContent());
    Route::get('/__correlation-throwing-probe', function () {
        throw new RuntimeException('boom');
    });
    Route::get('/__correlation-binding-probe', function (CorrelationId $correlationId) {
        return response()->json(['correlation_id' => $correlationId->get()]);
    });
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

test('binds a client-provided X-Correlation-Id for the request', function () {
    $response = $this->withHeader('X-Correlation-Id', 'client-provided-id')
        ->getJson('/__correlation-binding-probe');

    $response->assertOk()
        ->assertExactJson(['correlation_id' => 'client-provided-id']);

    expect($response->headers->get('X-Correlation-Id'))->toBe('client-provided-id');
});

test('binds the generated correlation id for the request when the header is absent', function () {
    $response = $this->getJson('/__correlation-binding-probe');

    $response->assertOk();

    $bound = $response->json('correlation_id');
    $echoed = $response->headers->get('X-Correlation-Id');

    expect($bound)
        ->toBe($echoed)
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

test('a throwing request still echoes the header and logs exactly one request line with status 500', function () {
    $stream = tempnam(sys_get_temp_dir(), 'correlation-stdout-');

    config()->set('logging.channels.stdout.handler_with.stream', $stream);

    $response = $this->withHeader('X-Correlation-Id', 'error-correlation-id')
        ->get('/__correlation-throwing-probe');

    $response->assertStatus(500);

    expect($response->headers->get('X-Correlation-Id'))->toBe('error-correlation-id');

    $requestLines = array_values(array_filter(
        array_map(
            fn (string $line) => json_decode($line, true),
            array_filter(explode("\n", file_get_contents($stream))),
        ),
        fn ($line) => $line !== null && $line['message'] === 'request.handled',
    ));

    expect($requestLines)->toHaveCount(1);

    $line = $requestLines[0];

    expect($line['context']['correlation_id'])->toBe('error-correlation-id')
        ->and($line['context']['method'])->toBe('GET')
        ->and($line['context']['path'])->toBe('/__correlation-throwing-probe')
        ->and($line['context']['status'])->toBe(500)
        ->and($line['context'])->toHaveKey('duration_ms');

    unlink($stream);
});
