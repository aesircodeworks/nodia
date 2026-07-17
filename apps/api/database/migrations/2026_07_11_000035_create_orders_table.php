<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * orders: the buyer's purchase, created by converting a hold
 * (system-design 8.3, stage-07 plan Data model "orders"). customer_id
 * and event_id are real FKs like holds' own cross-context columns;
 * hold_id is a plain uuid with no FK into Inventory's table by design,
 * and its unique index makes double conversion of one hold structurally
 * impossible. promo_code_id is a plain nullable uuid here; the
 * promo_codes migration adds the FK constraint when that table lands
 * later in this stage, keeping each table in its own slice's migration.
 * status is a string backed by App\Orders\Enums\OrderStatus; every
 * transition is a conditional UPDATE checked by affected-row count in
 * App\Orders\Actions (master plan test-first rule 2). The four money
 * columns share the row's single currency (data-conventions Money);
 * the total CHECK keeps the arithmetic honest and fees_amount stays 0
 * in this stage. The (tenant_id, created_at, id) index backs the staff
 * list's deterministic cursor order; the partial status index backs the
 * Stage 8a sweeper and the HoldExpired consumer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('customer_id')->constrained();
            $table->foreignUuid('event_id')->constrained();
            $table->uuid('promo_code_id')->nullable();
            $table->uuid('hold_id')->unique();
            $table->string('status')->default('pending');
            $table->integer('subtotal_amount');
            $table->integer('discount_amount');
            $table->integer('fees_amount');
            $table->integer('total_amount');
            $table->char('currency', 3);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->index(['tenant_id', 'created_at', 'id']);
            $table->index(['tenant_id', 'event_id']);
            $table->index(['tenant_id', 'customer_id']);
        });

        DB::statement('alter table orders add constraint orders_subtotal_non_negative check (subtotal_amount >= 0)');
        DB::statement('alter table orders add constraint orders_discount_non_negative check (discount_amount >= 0)');
        DB::statement('alter table orders add constraint orders_fees_non_negative check (fees_amount >= 0)');
        DB::statement('alter table orders add constraint orders_total_non_negative check (total_amount >= 0)');
        DB::statement('alter table orders add constraint orders_total_arithmetic check (total_amount = subtotal_amount - discount_amount + fees_amount)');
        DB::statement("create index orders_open_status_index on orders (tenant_id, status) where status in ('pending', 'awaiting_payment')");

        Rls::applyTenantPolicies('orders');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
