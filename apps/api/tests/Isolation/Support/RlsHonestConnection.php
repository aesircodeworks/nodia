<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Superusers and BYPASSRLS roles skip row-level security entirely, even
 * with FORCE, so a harness connected as one proves nothing. The compose
 * stack's `nodia` user and the CI service user are the cluster bootstrap
 * superuser, so when the configured user would bypass RLS this downgrades
 * the suite's connection to a dedicated unprivileged role, created
 * idempotently through the privileged connection. While still privileged
 * it also ensures the RLS group roles exist and grants the downgraded
 * role membership in both, mirroring what the roles migration grants the
 * production login user, so the suite can assume them via SET LOCAL ROLE.
 * When the configured user is already unprivileged (a production-like
 * posture), the connection is honest as-is and nothing changes: the roles
 * migration is expected to have provisioned membership already.
 */
final class RlsHonestConnection
{
    public const ROLE = 'nodia_isolation';

    public static function ensure(): void
    {
        if (config('database.connections.pgsql.username') === self::ROLE) {
            return;
        }

        $user = DB::selectOne(
            'select rolsuper or rolbypassrls as bypasses from pg_roles where rolname = current_user',
        );

        if (! $user->bypasses) {
            return;
        }

        DB::unprepared(<<<'SQL'
            do $$
            begin
                if not exists (select from pg_roles where rolname = 'nodia_isolation') then
                    create role nodia_isolation login password 'nodia_isolation'
                        nosuperuser nobypassrls nocreatedb nocreaterole;
                end if;
            end
            $$;
            grant usage, create on schema public to nodia_isolation;
            SQL);

        Rls::createRoles();

        DB::unprepared('grant nodia_app, nodia_platform to nodia_isolation');

        config()->set('database.connections.pgsql', [
            ...config('database.connections.pgsql'),
            'username' => self::ROLE,
            'password' => self::ROLE,
        ]);
        DB::purge('pgsql');
    }
}
