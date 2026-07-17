<?php

namespace App\Payments\Jobs;

use App\Payments\Actions\CompleteRefund;
use App\Payments\Actions\ConfirmPayment;
use App\Payments\Actions\FailPayment;
use App\Payments\Actions\FailRefund;
use App\Payments\Actions\RecordGatewayPayout;
use App\Payments\Actions\TransitionSubmerchantAccount;
use App\Payments\Enums\GatewayWebhookStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Enums\RefundStatus;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Gateways\NormalizedPaymentEvent;
use App\Payments\Gateways\NormalizedPayoutEvent;
use App\Payments\Gateways\NormalizedSubmerchantEvent;
use App\Payments\Gateways\WebhookKind;
use App\Payments\Models\GatewayWebhookEvent;
use App\Payments\Models\Payment;
use App\Payments\Models\Payout;
use App\Payments\Models\Refund;
use App\Payments\Models\SubmerchantAccount;
use App\Support\Audit\ActivityLogger;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Normalizes a persisted raw webhook row and applies the payment
 * transition (stage-08a plan, Endpoints "POST /v1/webhooks/{gateway}").
 * The payment is resolved by (gateway, gateway_reference) under the
 * platform-scope role because the callback carries no tenant
 * (system-design 4.3, amended by this stage to sanction system use for
 * webhook tenant resolution; every use is activity-logged), then the
 * conditional transition runs inside a tenant-scoped transaction.
 * Unmatched references and zero-row transitions mark the raw row
 * ignored rather than erroring, so replays and late events are inert;
 * a zero-row transition whose payment already carries the expected
 * terminal status is a duplicate and marks the row processed.
 */
final class ProcessGatewayWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $webhookEventId,
    ) {}

    public function handle(): void
    {
        $tx = app(TenantTransaction::class);
        $sentinel = config()->string('tenancy.platform_tenant_id');

        $row = $tx->asTenant($sentinel, fn (): ?GatewayWebhookEvent => GatewayWebhookEvent::query()->find($this->webhookEventId));

        if ($row === null || $row->status !== GatewayWebhookStatus::Received) {
            return;
        }

        $adapter = app(GatewayRegistry::class)->get($row->gateway);
        $normalized = $adapter?->normalizeWebhook($row->payload);

        if ($normalized === null) {
            $submerchantEvent = $adapter?->normalizeSubmerchantWebhook($row->payload);

            if ($submerchantEvent !== null) {
                $this->conclude($tx, $sentinel, $this->applySubmerchant($tx, $row, $submerchantEvent));

                return;
            }

            $payoutEvent = $adapter?->normalizePayoutWebhook($row->payload);

            if ($payoutEvent !== null) {
                $this->conclude($tx, $sentinel, $this->applyPayout($tx, $row, $payoutEvent));

                return;
            }

            $this->conclude($tx, $sentinel, GatewayWebhookStatus::Ignored);

            return;
        }

        if (in_array($normalized->kind, [WebhookKind::RefundCompleted, WebhookKind::RefundFailed], true)) {
            $this->conclude($tx, $sentinel, $this->applyRefund($tx, $row, $normalized));

            return;
        }

        $payment = $tx->asPlatform(function () use ($row, $normalized): ?Payment {
            $payment = Payment::query()
                ->where('gateway', $row->gateway)
                ->where('gateway_reference', $normalized->gatewayReference)
                ->first();

            app(ActivityLogger::class)->record(
                description: sprintf('Webhook tenant resolution for %s event %s', $row->gateway, $row->gateway_event_id),
                causer: null,
                event: 'platform_role_use',
                properties: [
                    'gateway' => $row->gateway,
                    'gateway_event_id' => $row->gateway_event_id,
                    'resolved_tenant_id' => $payment?->tenant_id,
                ],
            );

            return $payment;
        });

        if ($payment === null) {
            $this->conclude($tx, $sentinel, GatewayWebhookStatus::Ignored);

            return;
        }

        $status = $tx->asTenant($payment->tenant_id, fn (): GatewayWebhookStatus => $this->apply($payment, $normalized));

        $this->conclude($tx, $sentinel, $status);
    }

    /**
     * Refund events resolve the refund by gateway reference under the
     * platform role (the same sanctioned system use as payments, and
     * likewise activity-logged), then apply the conditional transition
     * inside a tenant-scoped transaction. Duplicates of an
     * already-applied outcome are processed; anything else late is
     * ignored.
     */
    private function applyRefund(TenantTransaction $tx, GatewayWebhookEvent $row, NormalizedPaymentEvent $normalized): GatewayWebhookStatus
    {
        $refund = $tx->asPlatform(function () use ($row, $normalized): ?Refund {
            $refund = Refund::query()
                ->select('refunds.*')
                ->join('payments', 'payments.id', '=', 'refunds.payment_id')
                ->where('refunds.gateway_reference', $normalized->gatewayReference)
                ->where('payments.gateway', $row->gateway)
                ->first();

            app(ActivityLogger::class)->record(
                description: sprintf('Webhook tenant resolution for %s event %s', $row->gateway, $row->gateway_event_id),
                causer: null,
                event: 'platform_role_use',
                properties: [
                    'gateway' => $row->gateway,
                    'gateway_event_id' => $row->gateway_event_id,
                    'resolved_tenant_id' => $refund?->tenant_id,
                ],
            );

            return $refund;
        });

        if ($refund === null) {
            return GatewayWebhookStatus::Ignored;
        }

        return $tx->asTenant($refund->tenant_id, function () use ($refund, $normalized): GatewayWebhookStatus {
            $applied = match ($normalized->kind) {
                WebhookKind::RefundCompleted => app(CompleteRefund::class)($refund->id),
                default => app(FailRefund::class)($refund->id, (string) $normalized->failureCode),
            };

            if ($applied !== null) {
                return GatewayWebhookStatus::Processed;
            }

            $expected = $normalized->kind === WebhookKind::RefundCompleted ? RefundStatus::Completed : RefundStatus::Failed;

            return Refund::query()->findOrFail($refund->id)->status === $expected
                ? GatewayWebhookStatus::Processed
                : GatewayWebhookStatus::Ignored;
        });
    }

    /**
     * Sub-merchant status events carry no tenant context, so the account
     * is resolved by (gateway, gateway_account_reference) under the
     * platform role, mirroring the payment/refund resolution above
     * (system-design 4.3, activity-logged); the conditional transition
     * then runs inside a tenant-scoped transaction. A zero-row transition
     * whose account already carries the reported status is a duplicate
     * and is processed; anything else stale or illegal is ignored.
     */
    private function applySubmerchant(TenantTransaction $tx, GatewayWebhookEvent $row, NormalizedSubmerchantEvent $normalized): GatewayWebhookStatus
    {
        $account = $tx->asPlatform(function () use ($row, $normalized): ?SubmerchantAccount {
            $account = SubmerchantAccount::query()
                ->where('gateway', $row->gateway)
                ->where('gateway_account_reference', $normalized->gatewayAccountReference)
                ->first();

            app(ActivityLogger::class)->record(
                description: sprintf('Webhook tenant resolution for %s event %s', $row->gateway, $row->gateway_event_id),
                causer: null,
                event: 'platform_role_use',
                properties: [
                    'gateway' => $row->gateway,
                    'gateway_event_id' => $row->gateway_event_id,
                    'resolved_tenant_id' => $account?->tenant_id,
                ],
            );

            return $account;
        });

        if ($account === null) {
            return GatewayWebhookStatus::Ignored;
        }

        return $tx->asTenant($account->tenant_id, function () use ($account, $normalized): GatewayWebhookStatus {
            $applied = app(TransitionSubmerchantAccount::class)($account->id, $normalized->status, $normalized->requirements);

            if ($applied !== null) {
                return GatewayWebhookStatus::Processed;
            }

            return SubmerchantAccount::query()->findOrFail($account->id)->status === $normalized->status
                ? GatewayWebhookStatus::Processed
                : GatewayWebhookStatus::Ignored;
        });
    }

    /**
     * Payout events carry no tenant context either, so the tenant is
     * resolved through the sub-merchant account by (gateway,
     * gateway_account_reference) under the platform role, mirroring
     * applySubmerchant above (system-design 4.3, activity-logged). The
     * upsert-or-transition then runs inside a tenant-scoped transaction.
     * A no-op that leaves the mirror already carrying the reported
     * status is a duplicate and is processed; anything else (no
     * sub-merchant match, or a status-changed event with no existing row
     * to transition) is ignored.
     */
    private function applyPayout(TenantTransaction $tx, GatewayWebhookEvent $row, NormalizedPayoutEvent $normalized): GatewayWebhookStatus
    {
        $account = $tx->asPlatform(function () use ($row, $normalized): ?SubmerchantAccount {
            $account = SubmerchantAccount::query()
                ->where('gateway', $row->gateway)
                ->where('gateway_account_reference', $normalized->gatewayAccountReference)
                ->first();

            app(ActivityLogger::class)->record(
                description: sprintf('Webhook tenant resolution for %s event %s', $row->gateway, $row->gateway_event_id),
                causer: null,
                event: 'platform_role_use',
                properties: [
                    'gateway' => $row->gateway,
                    'gateway_event_id' => $row->gateway_event_id,
                    'resolved_tenant_id' => $account?->tenant_id,
                ],
            );

            return $account;
        });

        if ($account === null) {
            return GatewayWebhookStatus::Ignored;
        }

        return $tx->asTenant($account->tenant_id, function () use ($row, $account, $normalized): GatewayWebhookStatus {
            $applied = app(RecordGatewayPayout::class)($account->tenant_id, $row->gateway, $normalized);

            if ($applied !== null) {
                return GatewayWebhookStatus::Processed;
            }

            $existing = Payout::query()
                ->where('gateway', $row->gateway)
                ->where('gateway_reference', $normalized->gatewayReference)
                ->first();

            return $existing !== null && $existing->status === $normalized->status
                ? GatewayWebhookStatus::Processed
                : GatewayWebhookStatus::Ignored;
        });
    }

    private function apply(Payment $payment, NormalizedPaymentEvent $normalized): GatewayWebhookStatus
    {
        // A fee in another currency would be silently relabeled with the
        // payment currency when persisted, so the event is ignored instead.
        if ($normalized->kind === WebhookKind::Confirmed && $normalized->fee?->currency !== $payment->currency) {
            return GatewayWebhookStatus::Ignored;
        }

        $applied = match ($normalized->kind) {
            WebhookKind::Confirmed => app(ConfirmPayment::class)($payment->id, $normalized->fee),
            WebhookKind::Failed => app(FailPayment::class)($payment->id, (string) $normalized->failureCode),
            WebhookKind::RefundCompleted, WebhookKind::RefundFailed => null,
        };

        if ($applied !== null) {
            return GatewayWebhookStatus::Processed;
        }

        $expected = $normalized->kind === WebhookKind::Confirmed ? PaymentStatus::Confirmed : PaymentStatus::Failed;

        // A duplicate of an already-applied outcome is processed; a late
        // event against any other terminal (or a past-window confirm still
        // sitting on initiated) is ignored, never applied.
        return Payment::query()->findOrFail($payment->id)->status === $expected
            ? GatewayWebhookStatus::Processed
            : GatewayWebhookStatus::Ignored;
    }

    private function conclude(TenantTransaction $tx, string $sentinel, GatewayWebhookStatus $status): void
    {
        $tx->asTenant($sentinel, function () use ($status): void {
            DB::table('gateway_webhook_events')
                ->where('id', $this->webhookEventId)
                ->where('status', GatewayWebhookStatus::Received->value)
                ->update([
                    'status' => $status->value,
                    'processed_at' => Date::now(),
                    'updated_at' => Date::now(),
                ]);
        });
    }
}
