<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * users carries no tenant_id and no RLS (stage-03 plan, Data model: staff
 * are a platform-level identity, ADR 007), so its 0001_01_01 migration
 * never granted the RLS group roles anything and none of Stage 3 so far
 * has needed it: staff token issuance and GET /v1/me run outside any
 * tenant transaction and never assume nodia_app or nodia_platform. The
 * memberships table this migration set precedes carries a user_id foreign
 * key, and this task's isolation suite proves memberships under both RLS
 * group roles, which means users rows must be reachable while one of them
 * is assumed. A later task's InviteUser Action will need exactly this
 * grant in production too, to create a new user row from inside a
 * tenant-scoped nodia_app transaction; landing it now rather than
 * retrofitting it keeps the grant next to the migration that first
 * requires it. Rls::grantUnscoped mirrors the Passport oauth tables'
 * justification (stage-03 plan, Data model, Risks): authentication
 * infrastructure keyed to an identity, not tenant-scoped domain data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Rls::grantUnscoped('users');
    }

    public function down(): void
    {
        DB::statement('revoke select, insert, update, delete on users from nodia_app, nodia_platform');
    }
};
