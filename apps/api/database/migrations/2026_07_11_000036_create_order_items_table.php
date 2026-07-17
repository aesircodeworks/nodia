<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * order_items: the priced lines of an order, snapshotted at conversion
 * time so ticket issuance at paid never re-reads Inventory's hold_items
 * and later price edits cannot change what was sold (stage-07 plan,
 * Data model "order_items"). tenant_id is denormalized, RLS still
 * required even though derivable through order_id, mirroring
 * hold_items. attendee_names is copied to tickets at issuance.
 * ticket_type_id is a plain uuid Catalog reference with no cross-context
 * FK, per the stage-07 plan's Data model: the priced line is a snapshot
 * and must survive whatever EventCatalog later does to its rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('order_id')->constrained();
            $table->uuid('ticket_type_id');
            $table->integer('quantity');
            $table->integer('unit_price_amount');
            $table->char('currency', 3);
            $table->json('attendee_names')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['order_id', 'ticket_type_id']);
        });

        DB::statement('alter table order_items add constraint order_items_quantity_positive check (quantity > 0)');
        DB::statement('alter table order_items add constraint order_items_unit_price_non_negative check (unit_price_amount >= 0)');

        Rls::applyTenantPolicies('order_items');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
