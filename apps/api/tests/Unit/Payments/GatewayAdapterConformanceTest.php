<?php

use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeGatewayScenarios;
use App\Payments\Gateways\GatewayAdapter;
use App\Payments\Gateways\GatewayPaymentRequest;
use App\Payments\Gateways\GatewayPaymentResult;
use App\Payments\Gateways\GatewayRefundRequest;
use App\Support\Money\Money;
use Illuminate\Support\Str;
use Tests\Support\Payments\GatewayAdapterConformanceContext;

/*
 * Stage-08d plan, Slice 1: the adapter-parameterized conformance suite
 * extracted from the Stage 8a FakeGateway tests. Registering FakeGateway
 * here, unchanged, proves the contract was extracted rather than
 * invented; a real adapter (later in this stage) runs the same
 * gatewayAdapterConformanceSuite() call.
 */

require_once __DIR__.'/../../Support/Payments/conformance.php';

gatewayAdapterConformanceSuite('fake', new GatewayAdapterConformanceContext(
    adapter: fn () => new FakeGateway(new FakeGatewayScenarios),
    paymentRequest: fn (string $paymentId) => new GatewayPaymentRequest(
        paymentId: $paymentId,
        orderId: (string) Str::uuid7(),
        method: 'card',
        amount: Money::of(12500, 'BRL'),
        details: ['token' => 'tok_approve'],
    ),
    unknownReferenceRefundRequest: function (GatewayAdapter $adapter): GatewayRefundRequest {
        // FakeGateway carries no reference ledger of its own; scripting a
        // decline on this same instance is how a stateless fake signals
        // "the gateway does not recognize this reference" deterministically,
        // the same way its other non-happy-path outcomes are controlled
        // (FakeGatewayTest).
        assert($adapter instanceof FakeGateway);
        $adapter->scenarios()->declineNextRefund('reference_unknown');

        return new GatewayRefundRequest(
            refundId: (string) Str::uuid7(),
            paymentGatewayReference: 'fake_unknown_reference',
            amount: Money::of(500, 'BRL'),
            idempotencyKey: (string) Str::uuid7(),
        );
    },
    signedWebhook: function (GatewayAdapter $adapter): array {
        assert($adapter instanceof FakeGateway);
        $delivery = $adapter->confirmationWebhook('fake_abc', Money::of(363, 'BRL'));

        return ['body' => $delivery->body, 'headers' => $delivery->headers];
    },
    tamperSignature: fn (array $headers): array => ['X-Fake-Signature' => 'bogus'] + $headers,
    assertIdempotencyKeyTransmitted: function (GatewayAdapter $adapter, string $paymentId, GatewayPaymentResult $result): void {
        // FakeGateway has no wire; the server-generated idempotency key (the
        // payment id) reaching the gateway is observable in the reference it
        // derives from that id. An HTTP-backed adapter instead asserts the
        // Idempotency-Key header on the recorded outbound request.
        expect($result->gatewayReference)->toContain($paymentId);
    },
));
