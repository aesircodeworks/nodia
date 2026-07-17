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

    public const RESOLVER_ROLE = 'nodia_resolver';

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
     * The narrow infrastructure role that answers anonymous domain
     * resolution (storefront Host lookup, Caddy domain verification)
     * before any tenant context exists. It is deliberately not
     * nodia_platform: system-design 4.3 reserves that role for
     * platform-scope staff with every use recorded in the activity log,
     * and anonymous traffic satisfies neither. Its entire privilege
     * surface is the per-table SELECT applyResolverReadPolicy() grants.
     */
    public static function createResolverRole(): void
    {
        DB::unprepared(<<<'SQL'
            do $$
            begin
                if not exists (select from pg_roles where rolname = 'nodia_resolver') then
                    create role nodia_resolver nologin nobypassrls nosuperuser nocreatedb nocreaterole;
                end if;
            end
            $$;
            SQL);
    }

    public static function grantResolverMembershipToCurrentUser(): void
    {
        DB::unprepared(<<<'SQL'
            do $$
            begin
                if not pg_has_role(current_user, 'nodia_resolver', 'member') then
                    execute format('grant nodia_resolver to %I', current_user);
                end if;
            end
            $$;
            SQL);
    }

    /**
     * Grants the resolver role its SELECT on one table and the permissive
     * read policy that lets the anonymous lookup see every tenant's rows.
     * Confined to the tables domain resolution genuinely needs (this
     * stage: tenant_domains only); the policy targets nodia_resolver
     * alone, so tenant-scoped and platform postures are unaffected.
     */
    public static function applyResolverReadPolicy(string $table): void
    {
        DB::statement("grant select on {$table} to nodia_resolver");

        DB::statement(<<<SQL
            create policy {$table}_resolver_read on {$table}
                for select to nodia_resolver using (true)
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
     * Grants full CRUD to nodia_app and nodia_platform without enabling
     * row level security. Reserved for the Passport oauth tables
     * (data-conventions Tenancy exception, Stage 3 plan): token and client
     * rows are authentication infrastructure keyed to identities rather
     * than tenant-scoped domain data, so there is no tenant_id column to
     * scope a policy against. Tenant isolation for customer tokens instead
     * rests on the token's tenant claim being checked at the application
     * layer (the tenant_mismatch behavior), not on the database.
     */
    public static function grantUnscoped(string $table): void
    {
        DB::statement("grant select, insert, update, delete on {$table} to nodia_app, nodia_platform");
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
