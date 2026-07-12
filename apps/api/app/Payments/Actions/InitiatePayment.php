<?php

namespace App\Payments\Actions;

use App\Inventory\Actions\ExtendHold;
use App\Inventory\Data\ExtendHoldData;
use App\Orders\Actions\MarkOrderAwaitingPayment;
use App\Orders\Actions\MarkOrderPaid;
use App\Orders\Data\OrderPaymentContextData;
use App\Orders\Enums\OrderStatus;
use App\Orders\Exceptions\InvalidOrderTransitionException;
use App\Payments\Data\InitiatePaymentData;
use App\Payments\Data\PaymentMethodOfferData;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Events\PaymentInitiated;
use App\Payments\Exceptions\GatewayUnavailableException;
use App\Payments\Exceptions\IdempotencyKeyReuseMismatchException;
use App\Payments\Exceptions\OrderNotPayableException;
use App\Payments\Exceptions\PaymentMethodNotAvailableException;
use App\Payments\Exceptions\SubmerchantNotActiveException;
use App\Payments\Gateways\GatewayPaymentOutcome;
use App\Payments\Gateways\GatewayPaymentRequest;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Models\Payment;
use App\Payments\Support\CircuitBreaker;
use App\Payments\Support\RequestHash;
use App\Support\Money\Money;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * POST /v1/storefront/orders/{order}/payments (stage-08a plan,
 * Endpoints; system-design 7.5, 7.6). The idempotency guarantee is the
 * (tenant_id, idempotency_key) unique constraint: the replay lookup is
 * an optimization, and the savepoint-isolated insert falls back to the
 * replay path when a concurrent initiation wins the constraint. The
 * gateway-facing idempotency key is the payment row's UUID; the client
 * header scopes API replay only and is never forwarded. A transport
 * failure throws gateway_unavailable and the request transaction rolls
 * the insert back, so the same key retries cleanly; a synchronous
 * decline commits the failed payment row and its events, the controller
 * rendering the 402 problem without an exception for exactly that
 * reason.
 */
final class InitiatePayment
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly GatewayRegistry $gateways,
        private readonly BuildPaymentMethodOffer $buildOffer,
        private readonly ConfirmPayment $confirmPayment,
        private readonly FailPayment $failPayment,
        private readonly MarkOrderAwaitingPayment $markOrderAwaitingPayment,
        private readonly MarkOrderPaid $markOrderPaid,
        private readonly ExtendHold $extendHold,
        private readonly OutboxRecorder $outbox,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function __invoke(OrderPaymentContextData $order, InitiatePaymentData $data, string $idempotencyKey): PaymentInitiationResult
    {
        $requestHash = RequestHash::compute($data->method, $data->detailsArray());

        $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $this->replay($existing, $requestHash, $order->id);
        }

        if ($order->status !== OrderStatus::Pending) {
            throw OrderNotPayableException::forOrder($order->id);
        }

        $offered = $this->offeredMethod($order, $data->method);

        if (! $this->breaker->allowsRequest($offered->gateway)) {
            throw GatewayUnavailableException::forGateway($offered->gateway);
        }

        try {
            $payment = DB::transaction(fn (): Payment => Payment::query()->create([
                'tenant_id' => (string) $this->tenantContext->tenantId(),
                'order_id' => $order->id,
                'gateway' => $offered->gateway,
                'method' => $offered->method,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'money' => $order->total,
                'status' => PaymentStatus::Initiated,
            ]));
        } catch (UniqueConstraintViolationException) {
            $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();

            return $this->replay($existing, $requestHash, $order->id);
        }

        $adapter = $this->gateways->get($offered->gateway) ?? throw PaymentMethodNotAvailableException::forMethod($data->method);

        try {
            $result = $adapter->createPayment(new GatewayPaymentRequest(
                paymentId: $payment->id,
                orderId: $order->id,
                method: $offered->method,
                amount: $order->total,
                details: $data->detailsArray(),
            ));
        } catch (GatewayUnavailableException $e) {
            $this->breaker->recordFailure($offered->gateway);

            throw $e;
        }

        $this->breaker->recordSuccess($offered->gateway);

        $payment->update([
            'gateway_reference' => $result->gatewayReference,
            'next_action' => $result->nextAction->toArray(),
        ]);

        return match ($result->outcome) {
            GatewayPaymentOutcome::Approved => $this->approve($payment, $order, $result->fee),
            GatewayPaymentOutcome::Declined => $this->decline($payment, (string) $result->failureCode),
            GatewayPaymentOutcome::Pending => $this->pend($payment, $order, $offered),
        };
    }

    private function replay(Payment $existing, string $requestHash, string $orderId): PaymentInitiationResult
    {
        // A key reused against a different order is a mismatch even with
        // an identical payload; replaying would hand back another order's
        // payment.
        if ($existing->order_id !== $orderId || ! hash_equals($existing->request_hash, $requestHash)) {
            throw IdempotencyKeyReuseMismatchException::make();
        }

        return new PaymentInitiationResult($existing, replayed: true, declined: false);
    }

    /**
     * A method absent because its gateway's sub-merchant is not active
     * (stage-08c plan, Slice 4; system-design 7.3) is a distinct 409
     * rather than the generic 422 payment_method_not_available: a second
     * pass with sub-merchant gating disabled tells the two cases apart.
     */
    private function offeredMethod(OrderPaymentContextData $order, string $method): PaymentMethodOfferData
    {
        foreach (($this->buildOffer)($order, excludeOpenBreakers: false) as $item) {
            if ($item->method === $method) {
                return $item;
            }
        }

        foreach (($this->buildOffer)($order, excludeOpenBreakers: false, requireActiveSubmerchant: false) as $item) {
            if ($item->method === $method) {
                throw SubmerchantNotActiveException::forGateway($item->gateway);
            }
        }

        throw PaymentMethodNotAvailableException::forMethod($method);
    }

    private function approve(Payment $payment, OrderPaymentContextData $order, Money $fee): PaymentInitiationResult
    {
        $this->outbox->record(PaymentInitiated::fromPayment($payment->fresh()));

        $confirmed = ($this->confirmPayment)($payment->id, $fee)
            ?? throw OrderNotPayableException::forOrder($order->id);

        $this->transitionOrder(fn () => ($this->markOrderAwaitingPayment)($order->id), $order->id);
        $this->transitionOrder(fn () => ($this->markOrderPaid)($order->id), $order->id);

        return new PaymentInitiationResult($confirmed, replayed: false, declined: false);
    }

    private function decline(Payment $payment, string $failureCode): PaymentInitiationResult
    {
        $this->outbox->record(PaymentInitiated::fromPayment($payment->fresh()));

        $failed = ($this->failPayment)($payment->id, $failureCode) ?? $payment->fresh();

        return new PaymentInitiationResult($failed, replayed: false, declined: true);
    }

    private function pend(Payment $payment, OrderPaymentContextData $order, PaymentMethodOfferData $offered): PaymentInitiationResult
    {
        $expiresAt = CarbonImmutable::instance(Date::now())->addMinutes((int) $offered->confirmationWindowMinutes);

        $payment->update(['expires_at' => $expiresAt]);

        $this->transitionOrder(fn () => ($this->markOrderAwaitingPayment)($order->id), $order->id);

        ($this->extendHold)(new ExtendHoldData($order->holdId, $expiresAt));

        $this->outbox->record(PaymentInitiated::fromPayment($payment->fresh()));

        return new PaymentInitiationResult($payment->fresh(), replayed: false, declined: false);
    }

    /**
     * A concurrent initiation that already moved the order wins; this
     * request's writes roll back behind the rendered problem.
     */
    private function transitionOrder(callable $transition, string $orderId): void
    {
        try {
            $transition();
        } catch (InvalidOrderTransitionException) {
            throw OrderNotPayableException::forOrder($orderId);
        }
    }
}
