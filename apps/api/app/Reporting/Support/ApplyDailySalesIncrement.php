<?php

namespace App\Reporting\Support;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `INSERT ... ON CONFLICT (tenant_id, event_id, ticket_type_id,
 * sales_date) DO UPDATE SET ... = report_daily_sales.column + excluded
 * .column` (stage-11 plan, Data model "report_daily_sales"): every
 * mutation is an additive increment evaluated in the statement, never a
 * read-then-write (master plan test-first rule 2), so parallel projector
 * workers on the same cell cannot lose an update. currency is written
 * only on first insert and never revised by a later conflict: the
 * columns share one currency per row, and the tenant's settlement
 * currency does not change under it (stage-11 plan, Data model
 * preamble).
 */
final class ApplyDailySalesIncrement
{
    public function __invoke(string $tenantId, DailySalesIncrement $increment): void
    {
        $now = Date::now();

        DB::statement(
            <<<'SQL'
            insert into report_daily_sales (
                id, tenant_id, event_id, ticket_type_id, sales_date,
                tickets_issued_count, tickets_refunded_count, gross_amount, refunded_amount,
                currency, created_at, updated_at
            )
            values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            on conflict (tenant_id, event_id, ticket_type_id, sales_date)
            do update set
                tickets_issued_count = report_daily_sales.tickets_issued_count + excluded.tickets_issued_count,
                tickets_refunded_count = report_daily_sales.tickets_refunded_count + excluded.tickets_refunded_count,
                gross_amount = report_daily_sales.gross_amount + excluded.gross_amount,
                refunded_amount = report_daily_sales.refunded_amount + excluded.refunded_amount,
                updated_at = excluded.updated_at
            SQL,
            [
                (string) Str::uuid7(),
                $tenantId,
                $increment->eventId,
                $increment->ticketTypeId,
                $increment->salesDate,
                $increment->ticketsIssuedCount,
                $increment->ticketsRefundedCount,
                $increment->grossAmount,
                $increment->refundedAmount,
                $increment->currency,
                $now,
                $now,
            ],
        );
    }
}
