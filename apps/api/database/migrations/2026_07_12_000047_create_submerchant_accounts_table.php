<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * submerchant_accounts: one row per tenant per gateway, the tenant as
 * registered with that gateway for split payments and payouts
 * (Sub-merchant, system-design 19; stage-08c plan, Data model
 * "submerchant_accounts"). The unique (tenant_id, gateway) is the
 * concurrency guard for duplicate onboarding starts. The partial unique
 * on (gateway, gateway_account_reference) lets webhook lookup by gateway
 * reference resolve exactly one account. status is backed by
 * App\Payments\Enums\SubmerchantStatus; every transition is a
 * conditional UPDATE checked by affected-row count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submerchant_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->string('gateway');
            $table->string('status')->default('pending');
            $table->string('gateway_account_reference')->nullable();
            $table->text('onboarding_url')->nullable();
            $table->jsonb('requirements')->default('[]');
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['tenant_id', 'gateway']);
            $table->index(['tenant_id', 'status']);
        });

        DB::statement('create unique index submerchant_accounts_gateway_reference_idx on submerchant_accounts (gateway, gateway_account_reference) where gateway_account_reference is not null');

        Rls::applyTenantPolicies('submerchant_accounts');
    }

    public function down(): void
    {
        Schema::dropIfExists('submerchant_accounts');
    }
};
