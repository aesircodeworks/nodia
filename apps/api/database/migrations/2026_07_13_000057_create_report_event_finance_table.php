<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * report_event_finance: one row per tenant and event, mirroring the
 * ledger's per-event totals (stage-11 plan, Data model
 * "report_event_finance"; system-design 7.3: gross charge, gateway fee,
 * platform commission, tenant net). A pre-aggregated read model, not a
 * source-of-truth table, so it carries the report_ prefix and its owning
 * model sets $table explicitly, mirroring report_daily_sales.
 *
 * Money semantics, decided at task 7/8 (stage-11 plan Data model note:
 * "Refund rows apply signed deltas to the commission and net columns"):
 * gross_amount, platform_commission_amount, and tenant_net_amount are
 * ALL netted by refunds, exactly mirroring the four ledger accounts the
 * ProjectLedgerEntries consumer already builds from the same two events
 * (system-design 7.3: "Refunds debit the tenant balance"). gross_amount
 * therefore reads as "gross confirmed minus gross refunded", not a
 * life-to-date charge total; gateway_fee_amount is frozen by refunds,
 * since the gateway never returns its fee. refunded_amount is a second,
 * separate face-value counter (never netted against gross_amount), the
 * same dual-column posture report_daily_sales already takes on its own
 * gross_amount/refunded_amount pair, so a dashboard can show both the
 * net figure and the raw refund total. This keeps the mandated identity
 * gross_amount - gateway_fee_amount - platform_commission_amount =
 * tenant_net_amount (stage-11 plan, TDD sequencing Slice 3 Unit test;
 * exit criterion 5) true after every payment and refund in any order,
 * by construction: every increment this migration's consumer writes
 * satisfies delta(net) = delta(gross) - delta(fee) - delta(commission).
 *
 * event_id is a real FK into EventCatalog's own table, the same posture
 * report_daily_sales already takes on its own event_id column (a
 * database-level constraint is allowed even though no Eloquent relation
 * ever crosses the context boundary); cascade on delete for the same
 * reason report_daily_sales chose it (EventCatalog ships no delete
 * endpoint for events, so the clause never fires in production, but
 * cascade avoids forcing every other context's test teardown to list
 * this table before deleting events). unique(tenant_id, event_id) is the
 * ON CONFLICT DO UPDATE target the ProjectEventFinance projector (task 8)
 * upserts against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_event_finance', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->integer('orders_paid_count')->default(0);
            $table->integer('refunds_count')->default(0);
            $table->bigInteger('gross_amount')->default(0);
            $table->bigInteger('gateway_fee_amount')->default(0);
            $table->bigInteger('platform_commission_amount')->default(0);
            $table->bigInteger('tenant_net_amount')->default(0);
            $table->bigInteger('refunded_amount')->default(0);
            $table->char('currency', 3);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['tenant_id', 'event_id']);
        });

        Rls::applyTenantPolicies('report_event_finance');
    }

    public function down(): void
    {
        Schema::dropIfExists('report_event_finance');
    }
};
