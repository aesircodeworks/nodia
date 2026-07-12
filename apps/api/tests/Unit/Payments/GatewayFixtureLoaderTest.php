<?php

use App\Payments\Exceptions\GatewayFixtureNotCoveredException;
use App\Payments\Support\Fixtures\GatewayFixtureLoader;
use Illuminate\Support\Facades\Http;

it('fakes http with the recorded exchanges for a gateway slug', function (): void {
    $loader = new GatewayFixtureLoader(base_path('tests/Fixtures/gateways'));

    $loader->fake('examplegw');

    $response = Http::withHeaders(['Idempotency-Key' => 'idem-1'])
        ->post('https://sandbox.examplegw.test/v1/payments', ['amount' => 1000, 'currency' => 'BRL']);

    expect($response->status())->toBe(201)
        ->and($response->json('id'))->toBe('pay_examplegw_001');
});

it('replays multiple exchanges matching the same request in recorded order, clamping to the last', function (): void {
    $loader = new GatewayFixtureLoader(base_path('tests/Fixtures/gateways'));

    $loader->fake('pollinggw');

    $poll = fn (): string => Http::get('https://sandbox.pollinggw.test/v1/submerchants/sm_1')->json('status');

    expect($poll())->toBe('pending')
        ->and($poll())->toBe('active')
        ->and($poll())->toBe('active');
});

it('fails loudly when a required header the fixture matches on is absent from the request', function (): void {
    $loader = new GatewayFixtureLoader(base_path('tests/Fixtures/gateways'));

    $loader->fake('examplegw');

    Http::post('https://sandbox.examplegw.test/v1/payments', ['amount' => 1000, 'currency' => 'BRL']);
})->throws(GatewayFixtureNotCoveredException::class);

it('fails loudly instead of hitting the network for a request the fixture set does not cover', function (): void {
    $loader = new GatewayFixtureLoader(base_path('tests/Fixtures/gateways'));

    $loader->fake('examplegw');

    Http::post('https://sandbox.examplegw.test/v1/payments', ['amount' => 999999, 'currency' => 'BRL']);
})->throws(GatewayFixtureNotCoveredException::class);

it('fails to load fixtures for a gateway slug with no fixture directory', function (): void {
    $loader = new GatewayFixtureLoader(base_path('tests/Fixtures/gateways'));

    expect(fn () => $loader->fake('no-such-gateway'))
        ->toThrow(GatewayFixtureNotCoveredException::class);
});
