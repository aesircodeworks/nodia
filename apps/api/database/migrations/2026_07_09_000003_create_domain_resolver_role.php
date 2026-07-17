<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the nodia_resolver role (NOLOGIN NOBYPASSRLS) and gives it its
 * entire privilege surface: SELECT on tenant_domains plus the permissive
 * resolver read policy. Anonymous domain resolution (storefront Host
 * lookup, Caddy domain verification) runs under this role instead of
 * nodia_platform, which system-design 4.3 reserves for platform-scope
 * staff with every use recorded in the activity log.
 *
 * CREATE ROLE requires the CREATEROLE privilege; the same pre-provisioning
 * escape hatch as the create_rls_roles migration applies (every statement
 * no-ops when infra provisioned the role and membership beforehand).
 */
return new class extends Migration
{
    public function up(): void
    {
        Rls::createResolverRole();
        Rls::grantResolverMembershipToCurrentUser();
        Rls::applyResolverReadPolicy('tenant_domains');
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_domains_resolver_read on tenant_domains');

        // The role is cluster-level and may be referenced by other
        // databases, so reversal only revokes what up() granted and never
        // drops it.
        DB::unprepared(<<<'SQL'
            do $$
            begin
                if exists (select from pg_roles where rolname = 'nodia_resolver') then
                    revoke select on tenant_domains from nodia_resolver;
                    execute format('revoke nodia_resolver from %I', current_user);
                end if;
            end
            $$;
            SQL);
    }
};
