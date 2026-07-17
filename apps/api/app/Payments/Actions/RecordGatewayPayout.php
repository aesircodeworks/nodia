<?php

namespace App\Payments\Actions;

use App\Payments\Enums\PayoutStatus;
use App\Payments\Events\PayoutExecuted;
use App\Payments\Gateways\NormalizedPayoutEvent;
use App\Payments\Models\Payout;
use App\Support\Audit\ActivityLogger;
use App\Support\Outbox\OutboxRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Upserts a payouts row by (gateway, gateway_reference) from a
 * normalized payout webhook (stage-08c plan, Slice 5). A payout.created
 * event (the only one carrying amount) inserts the pending row; a
 * payout.status_changed event applies the transition matrix as a
 * conditional UPDATE checked by affected-row count, never
 * read-then-write, mirroring TransitionSubmerchantAccount. The
 * out-of-order forward-skip case (paid arriving before in_transit)
 * needs no special casing: pending is already a listed source for
 * paid. PayoutExecuted is recorded in the same transaction as the
 * transition landing on paid, and only on that transition; failed and
 * canceled payouts record no domain event.
 */
final class RecordGatewayPayout
{
    /**
     * @var array<string, list<PayoutStatus>>
     */
    private const array SOURCES = [
        PayoutStatus::InTransit->value => [PayoutStatus::Pending],
        PayoutStatus::Paid->value => [PayoutStatus::Pending, PayoutStatus::InTransit],
        PayoutStatus::Failed->value => [PayoutStatus::Pending, PayoutStatus::InTransit],
        PayoutStatus::Canceled->value => [PayoutStatus::Pending, PayoutStatus::InTransit],
    ];

    public function __construct(
        private readonly OutboxRecorder $outbox,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function __invoke(string $tenantId, string $gateway, NormalizedPayoutEvent $event): ?Payout
    {
        $existing = Payout::query()
            ->where('gateway', $gateway)
            ->where('gateway_reference', $event->gatewayReference)
            ->first();

        if ($existing === null) {
            return $this->insert($tenantId, $gateway, $event);
        }

        return $this->transition($existing, $event);
    }

    private function insert(string $tenantId, string $gateway, NormalizedPayoutEvent $event): ?Payout
    {
        // A status-changed event with no prior payout.created carries no
        // amount, so there is nothing to insert; the row must already
        // exist (the reconciliation poller is the backstop for a missed
        // creation webhook, stage-08c plan Slice 6).
        if ($event->amount === null) {
            return null;
        }

        try {
            return DB::transaction(function () use ($tenantId, $gateway, $event): Payout {
                $payout = Payout::query()->create([
                    'tenant_id' => $tenantId,
                    'gateway' => $gateway,
                    'gateway_reference' => $event->gatewayReference,
                    'money' => $event->amount,
                    'status' => $event->status,
                    'executed_at' => $event->status === PayoutStatus::Paid
                        ? ($event->executedAt ?? Date::now())
                        : $event->executedAt,
                ]);

                if ($event->status === PayoutStatus::Paid) {
                    $this->outbox->record(PayoutExecuted::fromPayout($payout));
                }

                $this->logStateChange($payout);

                return $payout;
            });
        } catch (UniqueConstraintViolationException) {
            $existing = Payout::query()
                ->where('gateway', $gateway)
                ->where('gateway_reference', $event->gatewayReference)
                ->first();

            return $existing === null ? null : $this->transition($existing, $event);
        }
    }

    private function transition(Payout $payout, NormalizedPayoutEvent $event): ?Payout
    {
        $sources = self::SOURCES[$event->status->value] ?? [];

        if ($sources === []) {
            return null;
        }

        return DB::transaction(function () use ($payout, $event, $sources): ?Payout {
            $updates = [
                'status' => $event->status->value,
                'updated_at' => Date::now(),
            ];

            if ($event->status === PayoutStatus::Paid) {
                $updates['executed_at'] = $event->executedAt ?? Date::now();
            }

            $affected = DB::table('payouts')
                ->where('id', $payout->id)
                ->whereIn('status', array_map(fn (PayoutStatus $status): string => $status->value, $sources))
                ->update($updates);

            if ($affected !== 1) {
                return null;
            }

            $fresh = Payout::query()->findOrFail($payout->id);

            if ($event->status === PayoutStatus::Paid) {
                $this->outbox->record(PayoutExecuted::fromPayout($fresh));
            }

            $this->logStateChange($fresh);

            return $fresh;
        });
    }

    private function logStateChange(Payout $payout): void
    {
        $this->activityLogger->record(
            description: sprintf('Payout %s moved to %s', $payout->id, $payout->status->value),
            causer: null,
            event: 'payout_state_changed',
            properties: [
                'payout_id' => $payout->id,
                'gateway' => $payout->gateway,
                'gateway_reference' => $payout->gateway_reference,
                'status' => $payout->status->value,
            ],
        );
    }
}
