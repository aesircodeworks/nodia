<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * roles carries the sanctioned nullable-tenant_id exception data-conventions
 * records (Tenancy section): NULL means a global template role maintained
 * by the platform, a set tenant_id means a tenant's own custom role
 * (system-design 5.3, stage-03 plan Data model). Templates must be
 * readable under every tenant's RLS context, which no sentinel-tenant row
 * could satisfy under a single-tenant predicate, so this does not use
 * Rls::applyTenantPolicies: the SELECT policy admits tenant_id IS NULL,
 * mutation policies never do (a NULL tenant_id can never equal
 * app.tenant_id, so nodia_app can never mutate a template at the database
 * level), and template maintenance goes only through nodia_platform's
 * roles_platform_write, the one write path a NULL tenant_id row can
 * satisfy. INSERT, UPDATE, and DELETE get their own policies rather than
 * one FOR ALL, so the read policy's template-admitting predicate is never
 * folded into the mutation posture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained();
            $table->string('name');
            $table->jsonb('capabilities')->default(new Expression("'[]'::jsonb"));
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['tenant_id', 'name']);
        });

        // Postgres treats NULLs as distinct under a normal unique index, so
        // the (tenant_id, name) constraint above never catches two
        // templates sharing a name; this partial index is the actual guard
        // (data-conventions custom-name format for indexes the builder
        // cannot express).
        DB::statement(<<<'SQL'
            create unique index roles_template_name_idx
                on roles (name) where tenant_id is null
            SQL);

        DB::statement('grant select, insert, update, delete on roles to nodia_app, nodia_platform');
        DB::statement('alter table roles enable row level security');
        DB::statement('alter table roles force row level security');

        DB::statement(<<<'SQL'
            create policy roles_template_or_tenant_read on roles
                for select
                using (
                    tenant_id is null
                    or tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                )
            SQL);

        DB::statement(<<<'SQL'
            create policy roles_tenant_insert on roles
                for insert
                with check (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            SQL);

        DB::statement(<<<'SQL'
            create policy roles_tenant_update on roles
                for update
                using (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
                with check (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            SQL);

        DB::statement(<<<'SQL'
            create policy roles_tenant_delete on roles
                for delete
                using (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            SQL);

        DB::statement(<<<'SQL'
            create policy roles_platform_write on roles
                for all to nodia_platform using (true) with check (true)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
