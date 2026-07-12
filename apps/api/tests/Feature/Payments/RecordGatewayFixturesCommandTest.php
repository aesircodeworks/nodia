<?php

use App\Payments\Support\Fixtures\GatewayFixtureRecorder;

final class TwoExchangeFixtureRecorder implements GatewayFixtureRecorder
{
    public function record(string $scenario): array
    {
        return [
            ['request' => ['method' => 'POST', 'url' => 'https://sandbox.test/a'], 'response' => ['status' => 200, 'body' => []]],
            ['request' => ['method' => 'POST', 'url' => 'https://sandbox.test/b'], 'response' => ['status' => 200, 'body' => []]],
        ];
    }
}

it('removes stale higher-index fixtures when re-recording a shorter scenario', function (): void {
    config(['payments.ci' => false]);
    config(['payments.fixture_recorders.recordtemp' => TwoExchangeFixtureRecorder::class]);

    $directory = base_path('tests/Fixtures/gateways/recordtemp');
    mkdir($directory, 0777, true);
    foreach (range(0, 4) as $index) {
        file_put_contents("{$directory}/happy-path-{$index}.json", '{}');
    }
    file_put_contents("{$directory}/other-0.json", '{}');

    try {
        $this->artisan('gateway:record-fixtures', ['slug' => 'recordtemp', 'scenario' => 'happy-path'])
            ->assertSuccessful();

        $remaining = array_map('basename', glob("{$directory}/*.json") ?: []);
        sort($remaining);

        expect($remaining)->toBe(['happy-path-0.json', 'happy-path-1.json', 'other-0.json']);
    } finally {
        array_map('unlink', glob("{$directory}/*.json") ?: []);
        rmdir($directory);
    }
});

it('fails immediately without recording anything when CI is set', function (): void {
    config(['payments.ci' => true]);

    $this->artisan('gateway:record-fixtures', ['slug' => 'examplegw', 'scenario' => 'happy-path'])
        ->assertFailed();
});

it('fails when no recorder is registered for the gateway slug', function (): void {
    config(['payments.ci' => false]);

    $this->artisan('gateway:record-fixtures', ['slug' => 'no-such-gateway', 'scenario' => 'happy-path'])
        ->assertFailed();
});
