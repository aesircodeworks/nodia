<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * refunds: one row per refund against a confirmed payment (system-design
 * 8.3 REFUND, stage-08b plan Data model "refunds"). The row is a single
 * monetary fact, so the principal value is bare amount paired with
 * currency; commission_amount (the returned commission) keeps the
 * suffix. The (tenant_id, idempotency_key) unique is the replay anchor
 * mirroring payments: a violation on insert routes to the replay path,
 * which compares request_hash. status is backed by
 * App\Payments\Enums\RefundStatus; every transition is a conditional
 * UPDATE checked by affected-row count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('payment_id')->constrained();
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('status')->default('pending');
            $table->string('reason')->nullable();
            $table->jsonb('ticket_ids')->nullable();
            $table->bigInteger('commission_amount')->default(0);
            $table->string('idempotency_key');
            $table->string('request_hash');
            $table->string('gateway_reference')->nullable();
            $table->string('failure_code')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index('payment_id');
        });

        DB::statement('alter table refunds add constraint refunds_amount_positive check (amount > 0)');
        DB::statement('alter table refunds add constraint refunds_commission_non_negative check (commission_amount >= 0)');

        Rls::applyTenantPolicies('refunds');
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
