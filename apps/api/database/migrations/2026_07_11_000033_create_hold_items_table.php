<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * hold_items: the GA line items of a hold (stage-06 plan Data model
 * "hold_items"). tenant_id is denormalized, RLS still required even
 * though derivable through hold_id. unique(hold_id, ticket_type_id)
 * keeps one line item per ticket type per hold; the
 * hold_items_quantity_positive CHECK is defense in depth behind the
 * request-layer `min:1` validation on App\Inventory\Data\
 * HoldItemInputData, mirroring ticket_types' own CHECK-plus-request-rule
 * pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hold_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('hold_id')->constrained();
            $table->foreignUuid('ticket_type_id')->constrained();
            $table->integer('quantity');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['hold_id', 'ticket_type_id']);
        });

        DB::statement('alter table hold_items add constraint hold_items_quantity_positive check (quantity > 0)');

        Rls::applyTenantPolicies('hold_items');
    }

    public function down(): void
    {
        Schema::dropIfExists('hold_items');
    }
};
