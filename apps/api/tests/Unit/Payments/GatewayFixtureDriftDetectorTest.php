<?php

use App\Payments\Support\Fixtures\GatewayFixtureDriftDetector;
use App\Payments\Support\Fixtures\GatewayFixtureRecorder;

function driftDetectorFixturesRoot(): string
{
    return base_path('tests/Fixtures/gateways');
}

function driftDetectorExampleFixture(): array
{
    return json_decode(
        file_get_contents(driftDetectorFixturesRoot().'/examplegw/create-payment.json'),
        true,
    );
}

it('reports no drift when the live sandbox response matches the recorded fixture', function (): void {
    $recorded = driftDetectorExampleFixture();

    $recorder = new class($recorded) implements GatewayFixtureRecorder
    {
        public function __construct(private readonly array $exchange) {}

        public function record(string $scenario): array
        {
            return [$this->exchange];
        }
    };

    $detector = new GatewayFixtureDriftDetector(driftDetectorFixturesRoot());

    $result = $detector->detect('examplegw', 'create-payment', $recorder);

    expect($result)->toBe([]);
});

it('detects drift when the live sandbox response diverges from the recorded fixture', function (): void {
    $recorded = driftDetectorExampleFixture();

    $mutated = $recorded;
    $mutated['response']['body']['status'] = 'confirmed';

    $recorder = new class($mutated) implements GatewayFixtureRecorder
    {
        public function __construct(private readonly array $exchange) {}

        public function record(string $scenario): array
        {
            return [$this->exchange];
        }
    };

    $detector = new GatewayFixtureDriftDetector(driftDetectorFixturesRoot());

    $result = $detector->detect('examplegw', 'create-payment', $recorder);

    expect($result)->not->toBe([])
        ->and($result[0]['gateway'])->toBe('examplegw')
        ->and($result[0]['scenario'])->toBe('create-payment');
});

it('reports drift when the live sandbox returns a different number of exchanges than recorded', function (): void {
    $recorded = driftDetectorExampleFixture();

    $recorder = new class($recorded) implements GatewayFixtureRecorder
    {
        public function __construct(private readonly array $exchange) {}

        public function record(string $scenario): array
        {
            return [$this->exchange, $this->exchange];
        }
    };

    $detector = new GatewayFixtureDriftDetector(driftDetectorFixturesRoot());

    $result = $detector->detect('examplegw', 'create-payment', $recorder);

    expect($result)->not->toBe([]);
});

it('reports drift when no fixture exists yet for the scenario being checked', function (): void {
    $recorder = new class implements GatewayFixtureRecorder
    {
        public function record(string $scenario): array
        {
            return [['request' => ['method' => 'GET', 'url' => 'https://x.test'], 'response' => ['status' => 200, 'body' => []]]];
        }
    };

    $detector = new GatewayFixtureDriftDetector(driftDetectorFixturesRoot());

    $result = $detector->detect('examplegw', 'no-such-scenario', $recorder);

    expect($result)->not->toBe([]);
});
