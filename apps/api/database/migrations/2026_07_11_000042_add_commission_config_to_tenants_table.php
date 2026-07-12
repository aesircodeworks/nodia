<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive Tenancy migration (stage-08b plan, Data model "tenants
 * alterations"): the platform commission rate in basis points of gross
 * and the refund commission policy flag (system-design 7.3). The design
 * fixes that a commission exists but not where the rate lives; the
 * tenant column is the plan decision, with per-event overrides flagged
 * as a later additive change. No format CHECK, mirroring
 * settlement_currency's precedent: the request layer is the
 * friendly-error gate for value ranges. Defaults backfill existing rows
 * via the fast-default path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->integer('commission_bps')->default(0)->after('settlement_currency');
            $table->string('refund_commission_policy', 16)->default('retained')->after('commission_bps');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['commission_bps', 'refund_commission_policy']);
        });
    }
};
