<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * payments: one row per payment attempt against an order (system-design
 * 8.3, stage-08a plan Data model "payments"). The row is a single
 * monetary fact, so the principal value is bare amount paired with
 * currency (data-conventions Money exception); fee_amount and
 * commission_amount are secondary and keep the suffix. The
 * (tenant_id, idempotency_key) unique makes the idempotency guarantee a
 * constraint, not a read-then-write check: a violation on insert routes
 * to the replay path. The partial unique on (gateway, gateway_reference)
 * lets webhook processing resolve exactly one payment, and the partial
 * expires_at index backs the sweeper and poller scans. status is backed
 * by App\Payments\Enums\PaymentStatus; every transition is a conditional
 * UPDATE checked by affected-row count in App\Payments\Actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('order_id')->constrained();
            $table->string('gateway');
            $table->string('method');
            $table->string('idempotency_key');
            $table->string('request_hash');
            $table->string('gateway_reference')->nullable();
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->bigInteger('fee_amount')->default(0);
            $table->bigInteger('commission_amount')->default(0);
            $table->string('status')->default('initiated');
            $table->string('failure_code')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index('order_id');
        });

        DB::statement('alter table payments add constraint payments_amount_non_negative check (amount >= 0)');
        DB::statement('alter table payments add constraint payments_fee_non_negative check (fee_amount >= 0)');
        DB::statement('alter table payments add constraint payments_commission_non_negative check (commission_amount >= 0)');
        DB::statement('create unique index payments_gateway_reference_idx on payments (gateway, gateway_reference) where gateway_reference is not null');
        DB::statement("create index payments_expiry_sweep_idx on payments (expires_at) where status = 'initiated'");

        Rls::applyTenantPolicies('payments');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
