<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The first tenant-scoped child table, under the standard Rls helper
 * policies with platform writes enabled: domain CRUD is platform-admin
 * surface in this stage (stage-02 plan, Data model). The domain column is
 * stored lowercase, normalized by the Action, and is unique platform-wide
 * because a domain resolves to exactly one tenant. The partial unique
 * index carries a custom name because the schema builder cannot express
 * partial indexes (data-conventions); it is the database guard that keeps
 * the make-primary transition from racing into two primaries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_domains', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->string('domain')->unique();
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->index('tenant_id');
        });

        DB::statement(<<<'SQL'
            create unique index tenant_domains_primary_per_tenant_idx
                on tenant_domains (tenant_id) where is_primary
            SQL);

        Rls::applyTenantPolicies('tenant_domains', platformWrite: true);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
    }
};
