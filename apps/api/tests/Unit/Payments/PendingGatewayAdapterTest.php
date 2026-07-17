<?php

use App\Payments\Exceptions\GatewayNotConfiguredException;
use App\Payments\Exceptions\WebhookSignatureInvalidException;
use App\Payments\Gateways\GatewayAdapter;
use App\Payments\Gateways\GatewayPaymentRequest;
use App\Payments\Gateways\GatewayRefundRequest;
use App\Payments\Gateways\PendingGatewayAdapter;
use App\Payments\Gateways\SubmerchantRegistrationRequest;
use App\Support\Money\Money;

/*
 * Stage-08d plan, Slice 3: the skeleton every registered-but-not-yet-
 * implemented gateway resolves to.
 */

it('implements the GatewayAdapter interface', function (): void {
    expect(new PendingGatewayAdapter('pending'))->toBeInstanceOf(GatewayAdapter::class);
});

it('declares supports-nothing capability flags', function (): void {
    $capabilities = (new PendingGatewayAdapter('pending'))->capabilities();

    expect($capabilities->methods)->toBe([])
        ->and($capabilities->currencies)->toBe([])
        ->and($capabilities->asyncConfirmation)->toBeFalse()
        ->and($capabilities->splitSupport)->toBeFalse();
});

it('throws GatewayNotConfigured from every non-verifier operation', function (): void {
    $adapter = new PendingGatewayAdapter('pending');

    expect(fn () => $adapter->createPayment(new GatewayPaymentRequest('pay_1', 'ord_1', 'card', Money::of(1000, 'BRL'))))
        ->toThrow(GatewayNotConfiguredException::class)
        ->and(fn () => $adapter->normalizeWebhook(['type' => 'payment.confirmed']))
        ->toThrow(GatewayNotConfiguredException::class)
        ->and(fn () => $adapter->normalizeSubmerchantWebhook(['type' => 'submerchant.status_changed']))
        ->toThrow(GatewayNotConfiguredException::class)
        ->and(fn () => $adapter->normalizePayoutWebhook(['type' => 'payout.created']))
        ->toThrow(GatewayNotConfiguredException::class)
        ->and(fn () => $adapter->queryPayment('ref_1'))
        ->toThrow(GatewayNotConfiguredException::class)
        ->and(fn () => $adapter->refund(new GatewayRefundRequest('rf_1', 'ref_1', Money::of(1000, 'BRL'), 'rf_1')))
        ->toThrow(GatewayNotConfiguredException::class)
        ->and(fn () => $adapter->queryRefund('ref_1'))
        ->toThrow(GatewayNotConfiguredException::class)
        ->and(fn () => $adapter->createSubmerchant(new SubmerchantRegistrationRequest('tenant_1', 'BRL', 'weekly')))
        ->toThrow(GatewayNotConfiguredException::class)
        ->and(fn () => $adapter->fetchSubmerchantStatus('acct_1'))
        ->toThrow(GatewayNotConfiguredException::class)
        ->and(fn () => $adapter->listPayouts())
        ->toThrow(GatewayNotConfiguredException::class);
});

it('rejects every webhook signature through the skeleton verifier', function (): void {
    $adapter = new PendingGatewayAdapter('pending');

    expect(fn () => $adapter->parseWebhook('{"id":"evt_1"}', ['X-Signature' => 'anything']))
        ->toThrow(WebhookSignatureInvalidException::class);
});
