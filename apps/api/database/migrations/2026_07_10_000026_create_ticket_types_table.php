<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ticket_types: purchasable line items on an event (stage-05a plan, Data
 * model, task breakdown item 7). tenant_id is denormalized onto this table
 * (system-design 4.2) rather than resolved through event_id, matching every
 * other tenant-scoped table's own posture; event_id is a second, non-null
 * FK to the owning event with its own index. price_amount/currency is the
 * Money pair (data-conventions Money, ADR 018); App\EventCatalog\Models\
 * TicketType casts it through App\Support\Money\MoneyCast onto a virtual
 * `price` attribute, never bare on the wire (a later task's TicketTypeData).
 * currency is not constrained by a CHECK here: task breakdown item 8
 * validates it against the tenant's settlement currency at the request
 * layer by calling App\Tenancy\Actions\ResolveTenantSettlementCurrency, the
 * same "request layer is the friendly-error gate" posture as
 * venues.country and events.timezone. requires_seat ships now as a stable
 * column Stage 6 will read; nothing in this codebase references seats yet
 * (stage-05a plan Non-goals).
 *
 * Two CHECK constraints back invariants a later task's CreateTicketTypeData/
 * UpdateTicketTypeData validates first at the request layer, mirroring
 * venues_capacity_positive and events_end_after_start's own precedent:
 *
 * - ticket_types_price_amount_non_negative: price_amount >= 0.
 * - ticket_types_sales_window: sales_end > sales_start, enforced only when
 *   both are set; either or both may be null (an open-ended or unscheduled
 *   sales window, stage-05a plan Data model).
 *
 * Standard single-table RLS through the shared helper with no platform
 * write policy, mirroring venues and events: ticket type mutation is
 * tenant admin surface (events.manage gated), not platform-admin surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('event_id')->constrained();
            $table->string('name');
            $table->integer('price_amount');
            $table->string('currency', 3);
            $table->timestampTz('sales_start')->nullable();
            $table->timestampTz('sales_end')->nullable();
            $table->boolean('requires_seat')->default(false);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->index(['tenant_id', 'event_id']);
            $table->index('event_id');
        });

        DB::statement('alter table ticket_types add constraint ticket_types_price_amount_non_negative check (price_amount >= 0)');

        DB::statement(<<<'SQL'
            alter table ticket_types add constraint ticket_types_sales_window check (
                sales_start is null or sales_end is null or sales_end > sales_start
            )
            SQL);

        Rls::applyTenantPolicies('ticket_types');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_types');
    }
};
