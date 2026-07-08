<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;

test('the default log channel is stdout with a JSON formatter', function () {
    expect(config('logging.default'))->toBe('stdout');

    $channel = config('logging.channels.stdout');

    expect($channel['driver'])->toBe('monolog')
        ->and($channel['handler'])->toBe(StreamHandler::class)
        ->and($channel['handler_with']['stream'])->toBe('php://stdout')
        ->and($channel['formatter'])->toBe(JsonFormatter::class);
});

test('a handled request emits exactly one structured JSON log line with context rendered', function () {
    $capturedStream = tempnam(sys_get_temp_dir(), 'stdout-channel-');

    config()->set('logging.channels.stdout.handler_with.stream', $capturedStream);

    Route::get('/__logging-test-probe', function () {
        Log::withContext(['correlation_id' => 'test-correlation-id']);
        Log::info('request handled', ['route' => '/__logging-test-probe']);

        return response()->noContent();
    });

    $this->get('/__logging-test-probe')->assertNoContent();

    $lines = array_values(array_filter(explode("\n", file_get_contents($capturedStream))));

    expect($lines)->toHaveCount(1);

    $line = json_decode($lines[0], true);

    expect($line)->not->toBeNull()
        ->and($line['message'])->toBe('request handled')
        ->and($line['level_name'])->toBe('INFO')
        ->and($line['context']['correlation_id'])->toBe('test-correlation-id')
        ->and($line['context']['route'])->toBe('/__logging-test-probe');

    unlink($capturedStream);
});
