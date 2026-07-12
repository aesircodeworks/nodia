<?php

namespace App\Payments\Actions;

use App\Payments\Gateways\GatewayPayoutRecord;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Gateways\NormalizedPayoutEvent;
use App\Payments\Models\Payout;
use App\Payments\Models\SubmerchantAccount;
use App\Support\Audit\ActivityLogger;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\Date;

/**
 * The missed-payout-webhook backstop (stage-08c plan, Slice 6;
 * system-design 13: sweepers backstop every path). Polls every
 * registered gateway's listPayouts and, for each record, resolves the
 * owning tenant through the sub-merchant account by (gateway,
 * gateway_account_reference) exactly as ProcessGatewayWebhook does,
 * since a poller record carries no tenant context either. A record with
 * no mirror row yet is inserted through RecordGatewayPayout (so a payout
 * that skips straight to paid still gets its PayoutExecuted event
 * exactly once); a record whose mirror already exists has any missed
 * status change applied through the same guarded transition the webhook
 * path uses (so a missed 'paid' still emits PayoutExecuted), is stamped
 * reconciled_at, and, when the gateway's reported amount or currency
 * diverges from the mirror, is flagged with discrepancy_amount plus an
 * activity log entry. A record whose account reference matches no sub-merchant
 * account is skipped: nothing exists yet to reconcile it against.
 */
final readonly class ReconcilePayouts
{
    public function __construct(
        private TenantTransaction $transactions,
        private GatewayRegistry $gateways,
        private RecordGatewayPayout $recordPayout,
        private ActivityLogger $activityLogger,
    ) {}

    public function __invoke(): int
    {
        $reconciled = 0;

        foreach ($this->gateways->all() as $identifier => $adapter) {
            foreach ($adapter->listPayouts() as $record) {
                if ($this->reconcileOne($identifier, $record)) {
                    $reconciled++;
                }
            }
        }

        return $reconciled;
    }

    private function reconcileOne(string $gateway, GatewayPayoutRecord $record): bool
    {
        $account = $this->transactions->asPlatform(
            fn (): ?SubmerchantAccount => SubmerchantAccount::query()
                ->where('gateway', $gateway)
                ->where('gateway_account_reference', $record->gatewayAccountReference)
                ->first(),
        );

        if ($account === null) {
            return false;
        }

        return $this->transactions->asTenant(
            $account->tenant_id,
            fn (): bool => $this->reconcileWithinTenant($account->tenant_id, $gateway, $record),
        );
    }

    private function reconcileWithinTenant(string $tenantId, string $gateway, GatewayPayoutRecord $record): bool
    {
        $existing = Payout::query()
            ->where('gateway', $gateway)
            ->where('gateway_reference', $record->gatewayReference)
            ->first();

        if ($existing === null) {
            return $this->createMissed($tenantId, $gateway, $record);
        }

        $this->applyReportedStatus($tenantId, $gateway, $existing, $record);

        $current = Payout::query()->findOrFail($existing->id);

        $this->flagDiscrepancy($current, $record);

        return true;
    }

    /**
     * A missed status webhook can leave the mirror behind the gateway's
     * actual state (e.g. still pending while the gateway has already
     * paid). Reusing RecordGatewayPayout applies the same guarded
     * transition the webhook path would have, emitting PayoutExecuted on
     * paid exactly once. The gateway record carries no amount for a
     * status-only reconciliation; the mirror row already holds the
     * authoritative amount.
     */
    private function applyReportedStatus(string $tenantId, string $gateway, Payout $existing, GatewayPayoutRecord $record): void
    {
        if ($record->status === $existing->status) {
            return;
        }

        $event = new NormalizedPayoutEvent(
            $record->gatewayAccountReference,
            $record->gatewayReference,
            $record->status,
            null,
            $record->executedAt,
        );

        ($this->recordPayout)($tenantId, $gateway, $event);
    }

    private function createMissed(string $tenantId, string $gateway, GatewayPayoutRecord $record): bool
    {
        $event = new NormalizedPayoutEvent(
            $record->gatewayAccountReference,
            $record->gatewayReference,
            $record->status,
            $record->amount,
            $record->executedAt,
        );

        $created = ($this->recordPayout)($tenantId, $gateway, $event);

        if ($created === null) {
            return false;
        }

        $created->forceFill(['reconciled_at' => Date::now()])->save();

        return true;
    }

    private function flagDiscrepancy(Payout $payout, GatewayPayoutRecord $record): void
    {
        if ($record->amount->equals($payout->money)) {
            $payout->forceFill([
                'reconciled_at' => Date::now(),
                'discrepancy_amount' => null,
            ])->save();

            return;
        }

        // A currency mismatch is not a subtractable divergence, so the
        // whole mirrored amount is flagged as unreconciled and the gateway
        // currency is preserved in the activity log rather than silently
        // dropped (which subtracting equal minor units across currencies
        // would do, reporting a phantom zero discrepancy).
        $currencyMismatch = $record->amount->currency !== $payout->currency;

        $discrepancy = $currencyMismatch
            ? $payout->amount
            : abs($record->amount->amount - $payout->amount);

        $payout->forceFill([
            'reconciled_at' => Date::now(),
            'discrepancy_amount' => $discrepancy,
        ])->save();

        $description = $currencyMismatch
            ? sprintf('Payout %s reconciliation found a currency mismatch: gateway reported %s, mirror holds %s', $payout->id, $record->amount->currency, $payout->currency)
            : sprintf('Payout %s reconciliation found a %d %s discrepancy against the gateway', $payout->id, $discrepancy, $payout->currency);

        $this->activityLogger->record(
            description: $description,
            causer: null,
            event: 'payout_discrepancy',
            properties: [
                'payout_id' => $payout->id,
                'gateway' => $payout->gateway,
                'gateway_reference' => $payout->gateway_reference,
                'mirrored_amount' => $payout->amount,
                'mirrored_currency' => $payout->currency,
                'gateway_amount' => $record->amount->amount,
                'gateway_currency' => $record->amount->currency,
                'currency_mismatch' => $currencyMismatch,
                'discrepancy_amount' => $discrepancy,
            ],
        );
    }
}
