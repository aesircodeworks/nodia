<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * The RLS regime every tenant-scoped table lives under (ADR 003,
 * system-design 4.1 and 4.3): two NOLOGIN NOBYPASSRLS group roles the
 * request wrapper assumes via SET LOCAL ROLE, and the per-table policy set
 * every tenant-scoped migration applies through applyTenantPolicies() in
 * the same migration that creates the table (data-conventions Tenancy).
 *
 * Grants are issued per table rather than through ALTER DEFAULT
 * PRIVILEGES: default privileges attach only to objects created by the
 * role they are declared for, so they would silently miss tables migrated
 * by a different database user.
 */
final class Rls
{
    public const APP_ROLE = 'nodia_app';

    public const PLATFORM_ROLE = 'nodia_platform';

    public static function createRoles(): void
    {
        DB::unprepared(<<<'SQL'
            do $$
            begin
                if not exists (select from pg_roles where rolname = 'nodia_app') then
                    create role nodia_app nologin nobypassrls nosuperuser nocreatedb nocreaterole;
                end if;

                if not exists (select from pg_roles where rolname = 'nodia_platform') then
                    create role nodia_platform nologin nobypassrls nosuperuser nocreatedb nocreaterole;
                end if;
            end
            $$;
            SQL);
    }

    /**
     * pg_has_role() is implicitly true for superusers, so the grant only
     * runs for unprivileged users that genuinely lack membership; a
     * pre-provisioned membership makes this a no-op instead of a
     * permission error.
     */
    public static function grantMembershipToCurrentUser(): void
    {
        DB::unprepared(<<<'SQL'
            do $$
            begin
                if not pg_has_role(current_user, 'nodia_app', 'member') then
                    execute format('grant nodia_app to %I', current_user);
                end if;

                if not pg_has_role(current_user, 'nodia_platform', 'member') then
                    execute format('grant nodia_platform to %I', current_user);
                end if;
            end
            $$;
            SQL);
    }

    /**
     * FORCE keeps the table owner subject to the policies, so migrations
     * and seeders run inside the regime instead of around it. The
     * two-argument current_setting() returns NULL when app.tenant_id was
     * never set and NULLIF maps the empty string a reset SET LOCAL leaves
     * behind to NULL too; either way the predicate matches zero rows,
     * deny by default. The platform write policy is opt-in because
     * system-design 4.3 grants the platform role cross-tenant reads
     * everywhere but writes only where a stage explicitly needs them.
     */
    public static function applyTenantPolicies(string $table, bool $platformWrite = false): void
    {
        DB::statement("grant select, insert, update, delete on {$table} to nodia_app, nodia_platform");
        DB::statement("alter table {$table} enable row level security");
        DB::statement("alter table {$table} force row level security");

        DB::statement(<<<SQL
            create policy {$table}_tenant_isolation on {$table}
                using (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
                with check (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            SQL);

        DB::statement(<<<SQL
            create policy {$table}_platform_read on {$table}
                for select to nodia_platform using (true)
            SQL);

        if ($platformWrite) {
            DB::statement(<<<SQL
                create policy {$table}_platform_write on {$table}
                    for all to nodia_platform using (true) with check (true)
                SQL);
        }
    }
}
