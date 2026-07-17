<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ticket_type_inventory: the narrow counter table from system-design 6.3
 * (stage-06 plan, Data model "ticket_type_inventory"), kept free of
 * metadata so hot updates do not contend with catalog reads. Keyed by
 * `id` (data-conventions UUID `id` rule governs over the system-design
 * 8.2 ERD's ticket_type_id-only key) plus a unique `ticket_type_id`.
 * tenant_id is denormalized here too, matching every other tenant-scoped
 * table's own posture, even though it is derivable through
 * ticket_type_id (data-conventions Tenancy).
 *
 * The invariant `sold + held <= quantity` (system-design 6, this stage's
 * core guard) is enforced twice: the conditional UPDATE checked by
 * affected-row count is the concurrency guard (App\Inventory\Actions\
 * AdjustInventoryQuantity and, from a later task, the held-increment
 * statement CreateHold issues); the three CHECK constraints below are
 * defense in depth that turn any future guard bug into a statement error
 * instead of a silent oversell.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_type_inventory', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('ticket_type_id')->constrained()->unique();
            $table->integer('quantity');
            $table->integer('held')->default(0);
            $table->integer('sold')->default(0);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
        });

        DB::statement('alter table ticket_type_inventory add constraint ticket_type_inventory_quantity_non_negative check (quantity >= 0)');
        DB::statement('alter table ticket_type_inventory add constraint ticket_type_inventory_sold_non_negative check (sold >= 0)');
        DB::statement('alter table ticket_type_inventory add constraint ticket_type_inventory_held_non_negative check (held >= 0)');
        DB::statement('alter table ticket_type_inventory add constraint ticket_type_inventory_no_oversell check (sold + held <= quantity)');

        Rls::applyTenantPolicies('ticket_type_inventory');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_type_inventory');
    }
};
