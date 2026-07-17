<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * promo_codes per system-design 8.3 and the stage-07 plan Data model.
 * discount_value is basis points for percentage codes (1000 = 10
 * percent, so fractional percentages stay integer) and minor units for
 * fixed_amount codes, whose currency is then required: data-conventions
 * allows no monetary value without a currency on the row, and the CHECK
 * enforces null-for-percentage / non-null-for-fixed. The usage CHECK is
 * a backstop behind ApplyPromoCode's conditional UPDATE (master plan
 * test-first rule 2). This migration also adds the FK from
 * orders.promo_code_id, deferred from the orders migration because
 * merged migrations are never edited and this table lands in a later
 * slice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->string('code');
            $table->string('discount_type');
            $table->integer('discount_value');
            $table->char('currency', 3)->nullable();
            $table->integer('usage_limit')->nullable();
            $table->integer('usage_count')->default(0);
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_to')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['tenant_id', 'code']);
        });

        DB::statement('alter table promo_codes add constraint promo_codes_value_positive check (discount_value > 0)');
        DB::statement("alter table promo_codes add constraint promo_codes_currency_by_type check ((discount_type = 'percentage' and currency is null) or (discount_type = 'fixed_amount' and currency is not null))");
        DB::statement('alter table promo_codes add constraint promo_codes_usage_within_limit check (usage_limit is null or usage_count <= usage_limit)');
        DB::statement('alter table promo_codes add constraint promo_codes_usage_non_negative check (usage_count >= 0)');

        DB::statement('alter table orders add constraint orders_promo_code_id_foreign foreign key (promo_code_id) references promo_codes (id)');

        Rls::applyTenantPolicies('promo_codes');
    }

    public function down(): void
    {
        DB::statement('alter table orders drop constraint if exists orders_promo_code_id_foreign');

        Schema::dropIfExists('promo_codes');
    }
};
