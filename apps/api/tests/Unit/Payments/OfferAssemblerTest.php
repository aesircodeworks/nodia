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
