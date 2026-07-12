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
 * exactly once); a record whose mirror already exists is stamped
 * reconciled_at and, when the gateway's reported amount diverges from
 * the mirror, flagged with discrepancy_amount plus an activity log
 * entry. A record whose account reference matches no sub-merchant
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

        $this->flagDiscrepancy($existing, $record);

        return true;
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
        $discrepancy = $record->amount->equals($payout->money)
            ? null
            : abs($record->amount->amount - $payout->amount);

        $payout->forceFill([
            'reconciled_at' => Date::now(),
            'discrepancy_amount' => $discrepancy,
        ])->save();

        if ($discrepancy === null) {
            return;
        }

        $this->activityLogger->record(
            description: sprintf('Payout %s reconciliation found a %d %s discrepancy against the gateway', $payout->id, $discrepancy, $payout->currency),
            causer: null,
            event: 'payout_discrepancy',
            properties: [
                'payout_id' => $payout->id,
                'gateway' => $payout->gateway,
                'gateway_reference' => $payout->gateway_reference,
                'mirrored_amount' => $payout->amount,
                'gateway_amount' => $record->amount->amount,
                'discrepancy_amount' => $discrepancy,
            ],
        );
    }
}
