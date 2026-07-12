<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payouts: a mirror of gateway payout objects (system-design 7.3, 8.3;
 * stage-08c plan, Data model "payouts"). Rows are created by webhook
 * ingestion or the reconciliation poller, never by an admin request. The
 * unique (gateway, gateway_reference) is the idempotence anchor for payout
 * webhooks and poller overlap. amount is bare, paired with currency, per
 * the data-conventions Money exception for rows that are themselves a
 * single monetary fact. status is backed by App\Payments\Enums\PayoutStatus;
 * every transition is a conditional UPDATE checked by affected-row count.
 * No ledger columns live here: the ledger remains the source of truth for
 * balances, this table reconciles against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->string('gateway');
            $table->string('gateway_reference');
            $table->bigInteger('amount');
            $table->string('currency');
            $table->string('status')->default('pending');
            $table->timestampTz('executed_at')->nullable();
            $table->timestampTz('reconciled_at')->nullable();
            $table->bigInteger('discrepancy_amount')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['gateway', 'gateway_reference']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });

        Rls::applyTenantPolicies('payouts');
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
