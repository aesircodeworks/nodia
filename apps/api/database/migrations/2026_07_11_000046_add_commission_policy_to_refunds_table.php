<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive Payments migration (stage-08b plan, Domain events): persist
 * the refund commission policy resolved at creation as a row fact, so
 * the completion transaction records it on RefundCompleted and the
 * ledger projection reads it deterministically. Inferring the policy
 * from commission_amount is wrong for a returned policy that yields zero
 * commission (a zero commission_bps tenant), which this column fixes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->string('commission_policy')->default('retained')->after('commission_amount');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropColumn('commission_policy');
        });
    }
};
