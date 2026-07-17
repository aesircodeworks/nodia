<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive Tenancy migration (stage-05a plan, task breakdown item 6 and the
 * "Currency constraint" note in the Data model): the section 8.1 tenant
 * model shipped by Stage 2 has no settlement currency column (only
 * branding, locale, and gateway configuration), but Stage 5a's ticket_types
 * currency is constrained to it. Confirmed shape before writing this
 * migration (recorded in the stage-05a execution journal's Decisions
 * section): a single ISO 4217 code, the plan's own assumed shape, string
 * rather than a currency-list table since the platform has exactly one
 * settlement currency per tenant with no history to track.
 *
 * No format CHECK is added here, mirroring venues.country's own precedent
 * (stage-05a plan task breakdown item 3): the request layer is the
 * friendly-error gate for a value list, and a CHECK would duplicate it
 * inside a migration that can never be edited once merged. The column
 * carries a DEFAULT so this ADD COLUMN backfills every existing tenant row
 * (including the sentinel platform tenant from the original tenants
 * migration) without a table rewrite, per PostgreSQL 11+'s fast-default
 * behavior for non-volatile defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('settlement_currency', 3)->default('USD')->after('payout_schedule');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('settlement_currency');
        });
    }
};
