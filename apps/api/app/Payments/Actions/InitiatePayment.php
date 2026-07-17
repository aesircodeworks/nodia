<?php

namespace App\Payments\Actions;

use App\Inventory\Actions\CheckHoldCommittable;
use App\Inventory\Actions\ExtendHold;
use App\Inventory\Data\ExtendHoldData;
use App\Inventory\Exceptions\HoldNotCommittableException;
use App\Orders\Actions\LockOrderForPayment;
use App\Orders\Actions\MarkOrderAwaitingPayment;
use App\Orders\Actions\MarkOrderExpired;
use App\Orders\Actions\MarkOrderPaid;
use App\Orders\Data\OrderPaymentContextData;
use App\Orders\Enums\OrderStatus;
use App\Orders\Exceptions\InvalidOrderTransitionException;
use App\Payments\Data\InitiatePaymentData;
use App\Payments\Data\PaymentMethodOfferData;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Events\PaymentInitiated;
use App\Payments\Exceptions\GatewayNotConfiguredException;
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
use App\Support\Audit\ActivityLogger;
use App\Support\Money\Money;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Tenancy\TenantContext;
use App\Tenancy\Actions\ResolveEnabledGateways;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    public const string MISMATCH_EVENT = 'payment_confirmed_after_hold_expired';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly GatewayRegistry $gateways,
        private readonly BuildPaymentMethodOffer $buildOffer,
        private readonly ConfirmPayment $confirmPayment,
        private readonly FailPayment $failPayment,
        private readonly MarkOrderAwaitingPayment $markOrderAwaitingPayment,
        private readonly MarkOrderPaid $markOrderPaid,
        private readonly MarkOrderExpired $markOrderExpired,
        private readonly LockOrderForPayment $lockOrder,
        private readonly CheckHoldCommittable $holdCommittable,
        private readonly ExtendHold $extendHold,
        private readonly ActivityLogger $activity,
        private readonly OutboxRecorder $outbox,
        private readonly CircuitBreaker $breaker,
        private readonly ResolveEnabledGateways $enabledGateways,
    ) {}

    public function __invoke(OrderPaymentContextData $order, InitiatePaymentData $data, string $idempotencyKey): PaymentInitiationResult
    {
        $requestHash = RequestHash::compute($data->method, $data->detailsArray());

        $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $this->replay($existing, $requestHash, $order->id);
        }

        // The FOR UPDATE claim serializes different-key initiations on
        // the order row for the rest of the request transaction; the
        // status check below then reads the winner's committed state,
        // never a stale pending snapshot, so only one request per order
        // ever reaches the gateway.
        $order = ($this->lockOrder)($order->id);

        // Re-check the key now that a lock loser can see the winner's
        // committed payment: a same-key race must replay, not fall
        // through to the status guard and report the order unpayable.
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

        // A hold the sweeper released, or one already past expires_at
        // that a lagging CancelOrderOnHoldExpired consumer has not yet
        // canceled the order for, must never reach the gateway: the
        // charge would confirm against inventory that is gone.
        if (! ($this->holdCommittable)($order->holdId)) {
            throw OrderNotPayableException::forOrder($order->id);
        }

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

        // A replayed synchronous decline must render the same 402 problem
        // the first attempt did: the failed payment row committed, so
        // answering 200 with it would read as success to a client that
        // timed out on the original request and retried.
        return new PaymentInitiationResult(
            $existing,
            replayed: true,
            declined: $existing->status === PaymentStatus::Failed,
        );
    }

    /**
     * A method absent because its gateway's sub-merchant is not active
     * (stage-08c plan, Slice 4; system-design 7.3) is a distinct 409
     * rather than the generic 422 payment_method_not_available: a second
     * pass with sub-merchant gating disabled tells the two cases apart.
     *
     * A method absent because the tenant's only relevant gateway is a
     * registered-but-not-configured skeleton (stage-08d plan, Slice 3:
     * PendingGatewayAdapter, whose capabilities support nothing so it
     * never appears in any offer pass above) gets its own 409
     * gateway_not_configured rather than the generic 422: the tenant
     * enabled a gateway that cannot yet serve any method, which is a
     * platform configuration gap, not a request the buyer got wrong. This
     * only fires when every enabled gateway is such a skeleton; if any
     * enabled gateway is actually configured, the method the buyer named
     * genuinely is not on offer and the generic 422 is correct.
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

        $unconfiguredGateway = null;
        $hasConfiguredGateway = false;

        foreach (($this->enabledGateways)((string) $this->tenantContext->tenantId()) as $identifier) {
            $adapter = $this->gateways->get($identifier);

            if ($adapter === null) {
                continue;
            }

            if ($adapter->capabilities()->methods === [] && $adapter->capabilities()->currencies === []) {
                $unconfiguredGateway ??= $identifier;

                continue;
            }

            $hasConfiguredGateway = true;
        }

        if ($unconfiguredGateway !== null && ! $hasConfiguredGateway) {
            throw GatewayNotConfiguredException::forGateway($unconfiguredGateway);
        }

        throw PaymentMethodNotAvailableException::forMethod($method);
    }

    private function approve(Payment $payment, OrderPaymentContextData $order, Money $fee): PaymentInitiationResult
    {
        $this->outbox->record(PaymentInitiated::fromPayment($payment->fresh()));

        $confirmed = ($this->confirmPayment)($payment->id, $fee)
            ?? throw OrderNotPayableException::forOrder($order->id);

        $this->transitionOrder(fn () => ($this->markOrderAwaitingPayment)($order->id), $order->id);

        try {
            // The savepoint scopes a dead-hold refusal to the paid arc:
            // the confirmed payment, its events, and awaiting_payment
            // survive so the compensation can commit them, mirroring
            // HandlePaymentConfirmed's async arc. Throwing here instead
            // would roll back the whole request and erase the record of
            // money the gateway already captured.
            DB::transaction(fn () => $this->transitionOrder(fn () => ($this->markOrderPaid)($order->id), $order->id));
        } catch (HoldNotCommittableException) {
            return $this->compensateDeadHold($confirmed, $order);
        }

        return new PaymentInitiationResult($confirmed, replayed: false, declined: false);
    }

    private function compensateDeadHold(Payment $confirmed, OrderPaymentContextData $order): PaymentInitiationResult
    {
        try {
            ($this->markOrderExpired)($order->id);
        } catch (InvalidOrderTransitionException) {
            // A racing expiry consumer already moved the order.
        }

        $this->activity->record(
            description: sprintf('Payment %s confirmed after its hold died; order %s expired, payment flagged for refund', $confirmed->id, $order->id),
            causer: null,
            event: self::MISMATCH_EVENT,
            properties: ['payment_id' => $confirmed->id, 'order_id' => $order->id],
        );

        Log::critical('payments.confirmed_after_hold_expired', [
            'payment_id' => $confirmed->id,
            'order_id' => $order->id,
            'tenant_id' => (string) $this->tenantContext->tenantId(),
            'action_required' => 'refund the confirmed payment',
        ]);

        return new PaymentInitiationResult($confirmed, replayed: false, declined: false, orderExpired: true);
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
