<?php

use App\Payments\Exceptions\GatewayUnknownException;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeGatewayScenarios;
use App\Payments\Gateways\GatewayRegistry;

/*
 * Stage-08d plan, Slice 1: registry resolution by slug, alongside the
 * adapter conformance suite.
 */

it('resolves a bound slug to its adapter', function (): void {
    $gateway = new FakeGateway(new FakeGatewayScenarios);
    $registry = new GatewayRegistry(['fake' => $gateway]);

    expect($registry->resolve('fake'))->toBe($gateway)
        ->and($registry->get('fake'))->toBe($gateway);
});

it('fails resolution with a typed error for an unbound slug', function (): void {
    $registry = new GatewayRegistry(['fake' => new FakeGateway(new FakeGatewayScenarios)]);

    expect(fn () => $registry->resolve('nonexistent'))
        ->toThrow(GatewayUnknownException::class)
        ->and($registry->get('nonexistent'))->toBeNull();
});
