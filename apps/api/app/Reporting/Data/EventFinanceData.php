<?php

namespace App\Reporting\Data;

use App\Reporting\Models\EventFinance;
use App\Support\Money\Money;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One report_event_finance row's wire shape (stage-11 plan, Endpoints
 * "EventFinanceData"): event_id, orders_paid_count, refunds_count, gross,
 * gateway_fees, platform_commission, tenant_net, refunded. All four of
 * gross, platform_commission, tenant_net, and refunded already reflect
 * refunds netted in (report_event_finance migration's money semantics
 * note); gateway_fees stays frozen by refunds, since the gateway never
 * returns its fee.
 *
 * event_id is deliberately snake_case in PHP (not the usual camelCase
 * eventId), the same reason App\Reporting\Data\DailySalesData keeps
 * sales_date snake_case and App\Payments\Data\LedgerEntryData keeps
 * created_at snake_case:
 * Illuminate\Pagination\AbstractCursorPaginator::getParametersForItem()
 * reads the cursor column's value off the *transformed* paginator item by
 * plain PHP property access matching the literal orderBy column name.
 * report_event_finance's own unique(tenant_id, event_id) constraint makes
 * a single orderBy('event_id') fully deterministic under RLS tenant
 * scoping, unlike report_daily_sales, so no separate id tiebreak column
 * (hidden or otherwise) is needed here.
 */
#[TypeScript]
#[MapName(SnakeCaseMapper::class)]
class EventFinanceData extends Data
{
    public function __construct(
        public string $event_id,
        public int $ordersPaidCount,
        public int $refundsCount,
        public Money $gross,
        public Money $gatewayFees,
        public Money $platformCommission,
        public Money $tenantNet,
        public Money $refunded,
    ) {}

    public static function fromModel(EventFinance $row): self
    {
        return new self(
            $row->event_id,
            $row->orders_paid_count,
            $row->refunds_count,
            $row->gross,
            $row->gateway_fee,
            $row->platform_commission,
            $row->tenant_net,
            $row->refunded,
        );
    }
}
