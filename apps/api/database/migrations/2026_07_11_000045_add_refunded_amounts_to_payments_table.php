<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive Payments migration (stage-08b plan, Data model "payments
 * alterations"): the refundable-amount reservation columns. Reserving a
 * refund is one conditional UPDATE incrementing both within their caps,
 * checked by affected-row count; a failed refund releases with the
 * compensating conditional decrement. The CHECKs are a second line of
 * defense behind that guard, never the guard itself. Defaults backfill
 * existing rows via the fast-default path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->bigInteger('refunded_amount')->default(0)->after('commission_amount');
            $table->bigInteger('refunded_commission_amount')->default(0)->after('refunded_amount');
        });

        DB::statement('alter table payments add constraint payments_refunded_within_amount check (refunded_amount >= 0 and refunded_amount <= amount)');
        DB::statement('alter table payments add constraint payments_refunded_commission_within_commission check (refunded_commission_amount >= 0 and refunded_commission_amount <= commission_amount)');
    }

    public function down(): void
    {
        DB::statement('alter table payments drop constraint payments_refunded_within_amount');
        DB::statement('alter table payments drop constraint payments_refunded_commission_within_commission');

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['refunded_amount', 'refunded_commission_amount']);
        });
    }
};
