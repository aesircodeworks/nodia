<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive EventCatalog migration (stage-10 plan, Data model
 * "ticket_types.max_per_customer"): the per-ticket-type purchase limit
 * the waiting room's purchase-limit enforcement (a later stage-10 task)
 * reads through App\EventCatalog\Data\HoldableTicketTypeData rather than
 * Inventory ever touching ticket_types directly (system-design 3.1,
 * tests/Architecture/ContextBoundariesTest.php). Nullable with no DEFAULT
 * needed for backfill: an absent column value is already null on every
 * existing row, meaning unlimited, exactly the inactive state this
 * column's absence represented before this migration. The CHECK mirrors
 * ticket_types_price_amount_non_negative's own precedent from the
 * creating migration (defense in depth behind CreateTicketTypeData/
 * UpdateTicketTypeData's own `> 0` request validation); a null value is
 * exempt, matching "null means unlimited". ticket_types already carries
 * its RLS policy from Stage 5a; a column addition needs no policy change
 * (stage-10 plan, Data model, same note as events.on_sale_policy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_types', function (Blueprint $table): void {
            $table->integer('max_per_customer')->nullable()->after('requires_seat');
        });

        DB::statement('alter table ticket_types add constraint ticket_types_max_per_customer_positive check (max_per_customer is null or max_per_customer > 0)');
    }

    public function down(): void
    {
        Schema::table('ticket_types', function (Blueprint $table): void {
            $table->dropColumn('max_per_customer');
        });
    }
};
