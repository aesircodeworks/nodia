<?php

namespace App\Reporting\Support;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `INSERT ... ON CONFLICT (tenant_id, event_id) DO UPDATE SET ... =
 * report_event_finance.column + excluded.column` (stage-11 plan, Data
 * model "report_event_finance"): every mutation is an additive
 * increment evaluated in the statement, never a read-then-write (master
 * plan test-first rule 2), so parallel projector workers on the same
 * event row cannot lose an update, mirroring
 * ApplyDailySalesIncrement's own posture. currency is written only on
 * first insert and never revised by a later conflict.
 */
final class ApplyEventFinanceIncrement
{
    public function __invoke(string $tenantId, EventFinanceIncrement $increment): void
    {
        $now = Date::now();

        DB::statement(
            <<<'SQL'
            insert into report_event_finance (
                id, tenant_id, event_id,
                orders_paid_count, refunds_count,
                gross_amount, gateway_fee_amount, platform_commission_amount, tenant_net_amount, refunded_amount,
                currency, created_at, updated_at
            )
            values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            on conflict (tenant_id, event_id)
            do update set
                orders_paid_count = report_event_finance.orders_paid_count + excluded.orders_paid_count,
                refunds_count = report_event_finance.refunds_count + excluded.refunds_count,
                gross_amount = report_event_finance.gross_amount + excluded.gross_amount,
                gateway_fee_amount = report_event_finance.gateway_fee_amount + excluded.gateway_fee_amount,
                platform_commission_amount = report_event_finance.platform_commission_amount + excluded.platform_commission_amount,
                tenant_net_amount = report_event_finance.tenant_net_amount + excluded.tenant_net_amount,
                refunded_amount = report_event_finance.refunded_amount + excluded.refunded_amount,
                updated_at = excluded.updated_at
            SQL,
            [
                (string) Str::uuid7(),
                $tenantId,
                $increment->eventId,
                $increment->ordersPaidCount,
                $increment->refundsCount,
                $increment->grossAmount,
                $increment->gatewayFeeAmount,
                $increment->platformCommissionAmount,
                $increment->tenantNetAmount,
                $increment->refundedAmount,
                $increment->currency,
                $now,
                $now,
            ],
        );
    }
}
