<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant aggregate root (system-design 8.1). As the root the table has
 * no tenant_id, so it does not use the Rls helper: its isolation policy
 * compares id to app.tenant_id instead, and its grants are issued here
 * alongside the custom policies. The policy is declared FOR SELECT on
 * purpose: without it PostgreSQL defaults to FOR ALL and reuses USING as
 * the implicit WITH CHECK, which would let a tenant-scoped connection
 * write its own row. Declared FOR SELECT, nodia_app's INSERT, UPDATE, and
 * DELETE match no policy at all; tenants are created only through the
 * platform role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->jsonb('branding_settings')->default(new Expression("'{}'::jsonb"));
            $table->string('default_locale');
            $table->jsonb('supported_locales');
            $table->jsonb('enabled_gateways')->default(new Expression("'[]'::jsonb"));
            $table->jsonb('payout_schedule')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
        });

        DB::statement('grant select, insert, update, delete on tenants to nodia_app, nodia_platform');
        DB::statement('alter table tenants enable row level security');
        DB::statement('alter table tenants force row level security');

        DB::statement(<<<'SQL'
            create policy tenants_tenant_isolation on tenants
                for select
                using (id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            SQL);

        DB::statement(<<<'SQL'
            create policy tenants_platform_read on tenants
                for select to nodia_platform using (true)
            SQL);

        DB::statement(<<<'SQL'
            create policy tenants_platform_write on tenants
                for all to nodia_platform using (true) with check (true)
            SQL);

        // Under FORCE ROW LEVEL SECURITY the owner's bare INSERT matches no
        // policy, so the sentinel goes in through the platform write policy.
        DB::transaction(function (): void {
            DB::statement('set local role nodia_platform');

            DB::table('tenants')->insert([
                'id' => config()->string('tenancy.platform_tenant_id'),
                'name' => 'Nodia Platform',
                'branding_settings' => '{}',
                'default_locale' => 'en',
                'supported_locales' => '["en"]',
                'enabled_gateways' => '[]',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::statement('reset role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
