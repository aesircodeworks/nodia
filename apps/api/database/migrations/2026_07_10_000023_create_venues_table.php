<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * venues: tenant-scoped physical locations (stage-05a plan, Data model,
 * task breakdown item 3, TDD slice 1). Standard single-table RLS through
 * the shared helper with no platform write policy: venue mutation is
 * tenant admin surface (CreateVenue/UpdateVenue running under nodia_app
 * inside the acting tenant's own transaction, gated by events.manage),
 * not platform-admin surface, mirroring customers and roles/memberships's
 * own precedent for the same posture. country is stored as the raw ISO
 * 3166-1 alpha-2 string with no format CHECK: the request layer
 * (App\EventCatalog\Support\Iso3166CountryCodes) is the friendly-error
 * gate, and a CHECK here would duplicate that closed list inside a
 * migration that can never be edited once merged (data-conventions). The
 * capacity CHECK is the structural backstop for the same invariant the
 * request layer validates first. No domain event is recorded for venue
 * mutations: the system-design 9.3 registry has none (stage-05a plan
 * Risks, "Venue mutations record no domain event").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->string('name');
            $table->string('address');
            $table->string('city');
            $table->string('country');
            $table->integer('capacity');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->index('tenant_id');
        });

        DB::statement('alter table venues add constraint venues_capacity_positive check (capacity > 0)');

        Rls::applyTenantPolicies('venues');
    }

    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
