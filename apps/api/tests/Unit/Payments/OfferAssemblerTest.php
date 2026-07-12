<?php

use App\EventCatalog\Data\AsyncPaymentPolicyData;
use App\Payments\Enums\PaymentMethodConfirmation;
use App\Payments\Gateways\GatewayCapabilities;
use App\Payments\Gateways\MethodCapability;
use App\Payments\Support\OfferAssembler;

/*
 * Stage-08a plan, Slice 3: offer assembly as a pure function of gateway
 * capabilities, tenant config, event policy, and inventory level.
 */

function capabilitiesFixture(): array
{
    return [
        'fake' => new GatewayCapabilities(
            methods: [
                new MethodCapability('card', PaymentMethodConfirmation::Sync, null),
                new MethodCapability('pix', PaymentMethodConfirmation::Async, 30),
            ],
            currencies: ['BRL', 'USD'],
            asyncConfirmation: true,
            splitSupport: false,
        ),
        'other' => new GatewayCapabilities(
            methods: [new MethodCapability('boleto', PaymentMethodConfirmation::Async, 4320)],
            currencies: ['BRL'],
            asyncConfirmation: true,
            splitSupport: false,
        ),
    ];
}

it('unions methods across enabled gateways that cover the currency', function (): void {
    $offer = new OfferAssembler()->assemble(
        capabilitiesFixture(),
        currency: 'BRL',
        policy: new AsyncPaymentPolicyData,
        remainingInventory: 100,
        defaultCutoff: 10,
    );

    expect(array_map(fn ($item) => [$item->method, $item->gateway], $offer))
        ->toBe([['card', 'fake'], ['pix', 'fake'], ['boleto', 'other']]);
});

it('drops gateways that do not cover the order currency', function (): void {
    $offer = new OfferAssembler()->assemble(
        capabilitiesFixture(),
        currency: 'USD',
        policy: new AsyncPaymentPolicyData,
        remainingInventory: 100,
        defaultCutoff: 10,
    );

    expect(array_map(fn ($item) => $item->method, $offer))->toBe(['card', 'pix']);
});

it('drops async methods when the policy disables slow methods', function (): void {
    $offer = new OfferAssembler()->assemble(
        capabilitiesFixture(),
        currency: 'BRL',
        policy: new AsyncPaymentPolicyData(slowMethodsEnabled: false),
        remainingInventory: 100,
        defaultCutoff: 10,
    );

    expect(array_map(fn ($item) => $item->method, $offer))->toBe(['card']);
});

it('drops async methods at or below the cutoff, preferring the event override', function (): void {
    $assembler = new OfferAssembler;

    $atDefaultCutoff = $assembler->assemble(capabilitiesFixture(), 'BRL', new AsyncPaymentPolicyData, remainingInventory: 10, defaultCutoff: 10);
    $aboveDefaultCutoff = $assembler->assemble(capabilitiesFixture(), 'BRL', new AsyncPaymentPolicyData, remainingInventory: 11, defaultCutoff: 10);
    $overriddenLower = $assembler->assemble(capabilitiesFixture(), 'BRL', new AsyncPaymentPolicyData(lowInventoryCutoff: 5), remainingInventory: 10, defaultCutoff: 10);

    expect(array_map(fn ($item) => $item->method, $atDefaultCutoff))->toBe(['card'])
        ->and(array_map(fn ($item) => $item->method, $aboveDefaultCutoff))->toBe(['card', 'pix', 'boleto'])
        ->and(array_map(fn ($item) => $item->method, $overriddenLower))->toBe(['card', 'pix', 'boleto']);
});

/*
 * Stage-08c plan, Slice 1: the split-support capability flag becomes
 * consequential for offer composition (system-design 7.3). Full
 * sub-merchant-status gating lands in Slice 4; here the assembler only
 * needs to consult the flag and the caller-supplied active map.
 */
function splitCapabilitiesFixture(): array
{
    return [
        'fake' => new GatewayCapabilities(
            methods: [new MethodCapability('card', PaymentMethodConfirmation::Sync, null)],
            currencies: ['BRL'],
            asyncConfirmation: true,
            splitSupport: false,
        ),
        'splitgw' => new GatewayCapabilities(
            methods: [new MethodCapability('pix', PaymentMethodConfirmation::Async, 30)],
            currencies: ['BRL'],
            asyncConfirmation: true,
            splitSupport: true,
        ),
    ];
}

it('withholds a split-support gateway when no active sub-merchant map is supplied', function (): void {
    $offer = new OfferAssembler()->assemble(
        splitCapabilitiesFixture(),
        currency: 'BRL',
        policy: new AsyncPaymentPolicyData,
        remainingInventory: 100,
        defaultCutoff: 10,
    );

    expect(array_map(fn ($item) => $item->gateway, $offer))->toBe(['fake']);
});

it('withholds a split-support gateway whose sub-merchant is not active', function (): void {
    $offer = new OfferAssembler()->assemble(
        splitCapabilitiesFixture(),
        currency: 'BRL',
        policy: new AsyncPaymentPolicyData,
        remainingInventory: 100,
        defaultCutoff: 10,
        submerchantActiveByGateway: ['splitgw' => false],
    );

    expect(array_map(fn ($item) => $item->gateway, $offer))->toBe(['fake']);
});

it('offers a split-support gateway whose sub-merchant is active', function (): void {
    $offer = new OfferAssembler()->assemble(
        splitCapabilitiesFixture(),
        currency: 'BRL',
        policy: new AsyncPaymentPolicyData,
        remainingInventory: 100,
        defaultCutoff: 10,
        submerchantActiveByGateway: ['splitgw' => true],
    );

    expect(array_map(fn ($item) => $item->gateway, $offer))->toBe(['fake', 'splitgw']);
});

it('never gates a gateway that does not support splits, regardless of the active map', function (): void {
    $offer = new OfferAssembler()->assemble(
        capabilitiesFixture(),
        currency: 'BRL',
        policy: new AsyncPaymentPolicyData,
        remainingInventory: 100,
        defaultCutoff: 10,
        submerchantActiveByGateway: [],
    );

    expect(array_map(fn ($item) => $item->gateway, $offer))->toBe(['fake', 'fake', 'other']);
});
