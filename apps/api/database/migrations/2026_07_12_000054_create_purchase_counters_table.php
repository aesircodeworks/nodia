<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * purchase_counters: one row per customer per ticket type, tracking the
 * quantity currently held plus already committed toward that ticket
 * type's max_per_customer limit (stage-10 plan, Data model
 * "purchase_counters"). tenant_id is denormalized even though derivable
 * through ticket_type_id, the same posture every other tenant-scoped
 * table in this app takes (system-design 4.2). unique(customer_id,
 * ticket_type_id) is the upsert conflict target
 * App\Inventory\Support\PurchaseCounters::increment relies on; the
 * ticket_type_id index backs the per-type lookup the same guard and
 * App\Inventory\Support\PurchaseCounters::decrement issue.
 *
 * The purchase_counters_quantity_non_negative CHECK is defense in depth
 * behind the guarded upsert and decrement statements in
 * App\Inventory\Support\PurchaseCounters, neither of which writes a
 * negative quantity by construction; mirrors ticket_type_inventory's own
 * CHECK-as-backstop posture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('customer_id')->constrained();
            $table->foreignUuid('ticket_type_id')->constrained();
            $table->integer('quantity')->default(0);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['customer_id', 'ticket_type_id']);
            $table->index('ticket_type_id');
        });

        DB::statement('alter table purchase_counters add constraint purchase_counters_quantity_non_negative check (quantity >= 0)');

        Rls::applyTenantPolicies('purchase_counters');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_counters');
    }
};
