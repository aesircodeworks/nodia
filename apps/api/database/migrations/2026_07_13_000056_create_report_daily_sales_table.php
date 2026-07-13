<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * report_daily_sales: one row per tenant, event, ticket type, and UTC
 * calendar day with sales activity (stage-11 plan, Data model
 * "report_daily_sales"). A pre-aggregated read model, not a
 * source-of-truth table, so it carries the explicit report_ prefix and
 * its owning model sets $table explicitly rather than relying on
 * Eloquent's name-guessing (stage-11 plan, Data model preamble).
 * event_id and ticket_type_id are real FKs into EventCatalog's own
 * tables, the same posture check_ins already takes on its own event_id
 * and ticket_id columns: a database-level constraint is allowed even
 * though no Eloquent relation ever crosses the context boundary
 * (stage-11 plan, Data model "report_daily_sales" event_id note). Both
 * cascade on delete, unlike check_ins's restrict: EventCatalog ships no
 * delete endpoint for events or ticket_types (confirmed at task 5, the
 * point this table's rows start actually being written by
 * ProjectDailySales), so the clause never fires in production either
 * way; cascade is chosen because report_daily_sales rows are written by
 * every ticket issuance and refund across the whole platform, so a
 * restrict clause would force every existing purchase-path test fixture
 * in every other context, not just Reporting's own, to list this table
 * in its teardown before deleting ticket_types or events, unlike
 * check_ins rows, which only CheckIn's own tests ever create.
 * unique(tenant_id, event_id, ticket_type_id, sales_date) is the
 * ON CONFLICT DO UPDATE target the ProjectDailySales projector (task 5)
 * upserts against; the plain event_id index backs event-scoped lookups
 * beyond that composite key's prefix, and (tenant_id, sales_date) backs
 * the dashboard's date-range queries. Both money columns are bigint:
 * they accumulate indefinitely across a projection's lifetime, unlike
 * the single-transaction integer amounts on orders and payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_daily_sales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ticket_type_id')->constrained()->cascadeOnDelete();
            $table->date('sales_date');
            $table->integer('tickets_issued_count')->default(0);
            $table->integer('tickets_refunded_count')->default(0);
            $table->bigInteger('gross_amount')->default(0);
            $table->bigInteger('refunded_amount')->default(0);
            $table->char('currency', 3);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['tenant_id', 'event_id', 'ticket_type_id', 'sales_date']);
            $table->index('event_id');
            $table->index(['tenant_id', 'sales_date']);
        });

        Rls::applyTenantPolicies('report_daily_sales');
    }

    public function down(): void
    {
        Schema::dropIfExists('report_daily_sales');
    }
};
