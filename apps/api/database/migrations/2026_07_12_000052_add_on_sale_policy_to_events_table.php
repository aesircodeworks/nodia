<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive EventCatalog migration (stage-10 plan, Data model
 * "events.on_sale_policy"): per-event high-demand configuration, cast to
 * App\EventCatalog\Data\OnSalePolicyData exactly as async_payment_policy
 * is (stage-05a precedent). Unlike async_payment_policy, this column is
 * added to an already-populated table, so it carries a column DEFAULT of
 * the inactive policy's wire shape rather than relying solely on the
 * Data class's constructor defaults: the fast-default path backfills
 * every existing row (mirroring commission_bps's own precedent) so a
 * pre-existing event reads back the inactive policy with no Action
 * involvement. events already carries its RLS policy from Stage 5a; a
 * column addition needs no policy change (stage-10 plan, Data model).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->jsonb('on_sale_policy')
                ->default(new Expression("'{\"high_demand\":false,\"admission_rate_per_minute\":null,\"challenge_required\":false}'::jsonb"))
                ->after('async_payment_policy');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('on_sale_policy');
        });
    }
};
