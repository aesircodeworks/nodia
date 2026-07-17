<?php

namespace Tests\Support\Payments;

use App\Payments\Gateways\GatewayAdapter;
use App\Payments\Gateways\GatewayPaymentRequest;
use App\Payments\Gateways\GatewayPaymentResult;
use App\Payments\Gateways\GatewayRefundRequest;
use Closure;

/**
 * The per-adapter wiring the conformance suite (stage-08d plan, Slice 1)
 * needs to exercise the GatewayAdapter contract without knowing anything
 * gateway-specific: how to build a payment request, how to arrange a
 * refund the adapter will decline, and how to sign and tamper a webhook
 * delivery. Every GatewayAdapter implementation supplies one of these.
 */
final readonly class GatewayAdapterConformanceContext
{
    /**
     * @param  Closure(): GatewayAdapter  $adapter  fresh adapter instance each call
     * @param  Closure(string $paymentId): GatewayPaymentRequest  $paymentRequest
     * @param  Closure(GatewayAdapter $adapter): GatewayRefundRequest  $unknownReferenceRefundRequest  arranges the given adapter instance, if needed, so the returned request refunds a reference the adapter will decline
     * @param  Closure(GatewayAdapter $adapter): array{body: string, headers: array<string, string>}  $signedWebhook
     * @param  Closure(array<string, string> $headers): array<string, string>  $tamperSignature
     * @param  Closure(GatewayAdapter $adapter, string $paymentId, GatewayPaymentResult $result): void  $assertIdempotencyKeyTransmitted  asserts the server-generated gateway idempotency key (the payment id) actually reached the gateway on createPayment, not merely that two calls agreed
     */
    public function __construct(
        public Closure $adapter,
        public Closure $paymentRequest,
        public Closure $unknownReferenceRefundRequest,
        public Closure $signedWebhook,
        public Closure $tamperSignature,
        public Closure $assertIdempotencyKeyTransmitted,
    ) {}
}
