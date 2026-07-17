<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the cluster-level RLS group roles (nodia_app and nodia_platform,
 * both NOLOGIN NOBYPASSRLS) and grants the migration's login user
 * membership in both, which is what lets request transactions run
 * SET LOCAL ROLE.
 *
 * CREATE ROLE requires the CREATEROLE privilege. Dev and CI migrate as the
 * Postgres container superuser, so this is free there; a managed PostgreSQL
 * that withholds CREATEROLE must have infra pre-provision both roles and
 * grant the application's login user membership before migrating, in which
 * case every statement here no-ops (roles are guarded on pg_roles,
 * membership on pg_has_role).
 */
return new class extends Migration
{
    public function up(): void
    {
        Rls::createRoles();
        Rls::grantMembershipToCurrentUser();
    }

    public function down(): void
    {
        // The roles are cluster-level and may be referenced by other
        // databases, so reversal only revokes this user's membership and
        // never drops them.
        DB::unprepared(<<<'SQL'
            do $$
            begin
                if exists (select from pg_roles where rolname = 'nodia_app') then
                    execute format('revoke nodia_app from %I', current_user);
                end if;

                if exists (select from pg_roles where rolname = 'nodia_platform') then
                    execute format('revoke nodia_platform from %I', current_user);
                end if;
            end
            $$;
            SQL);
    }
};
