<?php

use App\Payments\Exceptions\GatewayFixtureNotCoveredException;
use App\Payments\Support\Fixtures\GatewayFixtureLoader;
use Illuminate\Support\Facades\Http;

it('fakes http with the recorded exchanges for a gateway scenario', function (): void {
    $loader = new GatewayFixtureLoader(base_path('tests/Fixtures/gateways'));

    $loader->fake('examplegw', 'create-payment');

    $response = Http::withHeaders(['Idempotency-Key' => 'idem-1'])
        ->post('https://sandbox.examplegw.test/v1/payments', ['amount' => 1000, 'currency' => 'BRL']);

    expect($response->status())->toBe(201)
        ->and($response->json('id'))->toBe('pay_examplegw_001');
});

it('replays multiple exchanges matching the same request in recorded order, clamping to the last', function (): void {
    $loader = new GatewayFixtureLoader(base_path('tests/Fixtures/gateways'));

    $loader->fake('pollinggw', 'poll-submerchant');

    $poll = fn (): string => Http::get('https://sandbox.pollinggw.test/v1/submerchants/sm_1')->json('status');

    expect($poll())->toBe('pending')
        ->and($poll())->toBe('active')
        ->and($poll())->toBe('active');
});

it('loads only the requested scenario when another scenario shares the same matcher', function (): void {
    $root = sys_get_temp_dir().'/gateway-loader-'.uniqid();
    mkdir($root.'/multigw', 0777, true);

    $exchange = fn (string $status): string => json_encode([
        'request' => ['method' => 'GET', 'url' => 'https://sandbox.multigw.test/v1/status'],
        'response' => ['status' => 200, 'body' => ['status' => $status]],
    ]);

    file_put_contents($root.'/multigw/scenario-a-0.json', $exchange('a-only'));
    file_put_contents($root.'/multigw/scenario-b-0.json', $exchange('b-only'));

    try {
        $loader = new GatewayFixtureLoader($root);
        $loader->fake('multigw', 'scenario-b');

        $status = Http::get('https://sandbox.multigw.test/v1/status')->json('status');

        expect($status)->toBe('b-only');
    } finally {
        array_map('unlink', glob($root.'/multigw/*.json') ?: []);
        rmdir($root.'/multigw');
        rmdir($root);
    }
});

it('replays exchanges in numeric index order past ten exchanges', function (): void {
    $root = sys_get_temp_dir().'/gateway-loader-'.uniqid();
    mkdir($root.'/seqgw', 0777, true);

    foreach (range(0, 11) as $index) {
        file_put_contents($root."/seqgw/seq-{$index}.json", json_encode([
            'request' => ['method' => 'GET', 'url' => 'https://sandbox.seqgw.test/v1/poll'],
            'response' => ['status' => 200, 'body' => ['index' => $index]],
        ]));
    }

    try {
        $loader = new GatewayFixtureLoader($root);
        $loader->fake('seqgw', 'seq');

        $poll = fn (): int => Http::get('https://sandbox.seqgw.test/v1/poll')->json('index');

        $seen = array_map(fn (): int => $poll(), range(0, 11));

        expect($seen)->toBe(range(0, 11));
    } finally {
        array_map('unlink', glob($root.'/seqgw/*.json') ?: []);
        rmdir($root.'/seqgw');
        rmdir($root);
    }
});

it('fails loudly when a required header the fixture matches on is absent from the request', function (): void {
    $loader = new GatewayFixtureLoader(base_path('tests/Fixtures/gateways'));

    $loader->fake('examplegw', 'create-payment');

    Http::post('https://sandbox.examplegw.test/v1/payments', ['amount' => 1000, 'currency' => 'BRL']);
})->throws(GatewayFixtureNotCoveredException::class);

it('fails loudly instead of hitting the network for a request the fixture set does not cover', function (): void {
    $loader = new GatewayFixtureLoader(base_path('tests/Fixtures/gateways'));

    $loader->fake('examplegw', 'create-payment');

    Http::post('https://sandbox.examplegw.test/v1/payments', ['amount' => 999999, 'currency' => 'BRL']);
})->throws(GatewayFixtureNotCoveredException::class);

it('fails to load fixtures for a gateway slug with no fixture directory', function (): void {
    $loader = new GatewayFixtureLoader(base_path('tests/Fixtures/gateways'));

    expect(fn () => $loader->fake('no-such-gateway', 'create-payment'))
        ->toThrow(GatewayFixtureNotCoveredException::class);
});
