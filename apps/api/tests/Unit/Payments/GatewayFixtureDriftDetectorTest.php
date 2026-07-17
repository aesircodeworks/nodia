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
        file_get_contents(driftDetectorFixturesRoot().'/examplegw/create-payment-0.json'),
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

it('does not report drift when the live sandbox returns raw credentials the committed fixture stores redacted', function (): void {
    $root = sys_get_temp_dir().'/gateway-drift-'.uniqid();
    mkdir($root.'/examplegw', 0777, true);
    file_put_contents(
        $root.'/examplegw/create-payment-0.json',
        json_encode([
            'request' => ['method' => 'POST', 'url' => 'https://sandbox.test/v1/payments', 'headers' => ['Authorization' => '[REDACTED]']],
            'response' => ['status' => 201, 'body' => ['id' => 'pay_1']],
        ]),
    );

    $recorder = new class implements GatewayFixtureRecorder
    {
        public function record(string $scenario): array
        {
            return [[
                'request' => ['method' => 'POST', 'url' => 'https://sandbox.test/v1/payments', 'headers' => ['Authorization' => 'Bearer sk_live_supersecrettoken1234']],
                'response' => ['status' => 201, 'body' => ['id' => 'pay_1']],
            ]];
        }
    };

    $detector = new GatewayFixtureDriftDetector($root);

    expect($detector->detect('examplegw', 'create-payment', $recorder))->toBe([]);

    array_map('unlink', glob($root.'/examplegw/*.json') ?: []);
    rmdir($root.'/examplegw');
    rmdir($root);
});

it('compares recorded exchanges in numeric index order past ten exchanges', function (): void {
    $root = sys_get_temp_dir().'/gateway-drift-'.uniqid();
    mkdir($root.'/seqgw', 0777, true);

    $exchanges = [];
    foreach (range(0, 11) as $index) {
        $exchange = [
            'request' => ['method' => 'GET', 'url' => 'https://sandbox.test/poll'],
            'response' => ['status' => 200, 'body' => ['index' => $index]],
        ];
        $exchanges[] = $exchange;
        file_put_contents($root."/seqgw/seq-{$index}.json", json_encode($exchange));
    }

    $recorder = new class($exchanges) implements GatewayFixtureRecorder
    {
        public function __construct(private readonly array $exchanges) {}

        public function record(string $scenario): array
        {
            return $this->exchanges;
        }
    };

    try {
        $detector = new GatewayFixtureDriftDetector($root);

        expect($detector->detect('seqgw', 'seq', $recorder))->toBe([]);
    } finally {
        array_map('unlink', glob($root.'/seqgw/*.json') ?: []);
        rmdir($root.'/seqgw');
        rmdir($root);
    }
});

it('does not treat another scenario sharing a name prefix as part of the scenario', function (): void {
    $root = sys_get_temp_dir().'/gateway-drift-'.uniqid();
    mkdir($root.'/prefixgw', 0777, true);

    $exchange = [
        'request' => ['method' => 'GET', 'url' => 'https://sandbox.test/poll'],
        'response' => ['status' => 200, 'body' => ['id' => 'poll']],
    ];
    file_put_contents($root.'/prefixgw/poll-0.json', json_encode($exchange));
    file_put_contents($root.'/prefixgw/poll-refund-0.json', json_encode([
        'request' => ['method' => 'GET', 'url' => 'https://sandbox.test/refund'],
        'response' => ['status' => 200, 'body' => ['id' => 'refund']],
    ]));

    $recorder = new class($exchange) implements GatewayFixtureRecorder
    {
        public function __construct(private readonly array $exchange) {}

        public function record(string $scenario): array
        {
            return [$this->exchange];
        }
    };

    try {
        $detector = new GatewayFixtureDriftDetector($root);

        expect($detector->detect('prefixgw', 'poll', $recorder))->toBe([]);
    } finally {
        array_map('unlink', glob($root.'/prefixgw/*.json') ?: []);
        rmdir($root.'/prefixgw');
        rmdir($root);
    }
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
