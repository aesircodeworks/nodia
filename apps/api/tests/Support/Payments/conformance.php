<?php

declare(strict_types=1);

use App\Payments\Exceptions\WebhookSignatureInvalidException;
use App\Payments\Gateways\GatewayPaymentResult;
use App\Payments\Gateways\GatewayRefundResult;
use Illuminate\Support\Str;
use Tests\Support\Payments\GatewayAdapterConformanceContext;

/**
 * The GatewayAdapter behavioral contract (stage-08d plan, Slice 1),
 * extracted from the Stage 8a FakeGateway tests rather than invented:
 * every adapter must satisfy these invariants regardless of which
 * gateway it wraps. Registering this suite against FakeGateway proves
 * extraction, not invention; a real adapter later runs the same
 * function unchanged.
 */
function gatewayAdapterConformanceSuite(string $label, GatewayAdapterConformanceContext $context): void
{
    describe("GatewayAdapter conformance: {$label}", function () use ($context): void {
        it('normalizes createPayment into a result carrying the gateway reference, deterministic on the idempotency key', function () use ($context): void {
            $adapter = ($context->adapter)();
            $paymentId = (string) Str::uuid7();
            $request = ($context->paymentRequest)($paymentId);

            $first = $adapter->createPayment($request);
            $second = $adapter->createPayment($request);

            expect($first)->toBeInstanceOf(GatewayPaymentResult::class)
                ->and($first->gatewayReference)->not->toBe('')
                ->and($second->gatewayReference)->toBe($first->gatewayReference);

            ($context->assertIdempotencyKeyTransmitted)($adapter, $paymentId, $first);
        });

        it('returns a typed failure from refund on an unknown reference, never an exception', function () use ($context): void {
            $adapter = ($context->adapter)();
            $request = ($context->unknownReferenceRefundRequest)($adapter);

            $result = $adapter->refund($request);

            expect($result)->toBeInstanceOf(GatewayRefundResult::class)
                ->and($result->accepted)->toBeFalse()
                ->and($result->failureCode)->not->toBeNull()
                ->and($result->gatewayReference)->toBeNull();
        });

        it('throws the typed verification failure when parseWebhook receives a tampered signature', function () use ($context): void {
            $adapter = ($context->adapter)();
            $delivery = ($context->signedWebhook)($adapter);
            $tampered = ($context->tamperSignature)($delivery['headers']);

            expect(fn () => $adapter->parseWebhook($delivery['body'], $tampered))
                ->toThrow(WebhookSignatureInvalidException::class);
        });

        it('declares capability flags that are internally consistent about async confirmation', function () use ($context): void {
            $adapter = ($context->adapter)();
            $capabilities = $adapter->capabilities();

            $hasAsyncMethod = collect($capabilities->methods)->contains(fn ($method) => $method->isAsync());

            expect($capabilities->asyncConfirmation)->toBe($hasAsyncMethod);
        });
    });
}
