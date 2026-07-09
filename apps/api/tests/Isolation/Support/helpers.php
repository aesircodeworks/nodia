<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Runs $fn inside a transaction where app.tenant_id is set for the
 * transaction's duration, mirroring how production request handlers will
 * scope RLS (system-design 4.1). set_config(..., true) is the bindable
 * equivalent of SET LOCAL, which cannot take query parameters.
 */
function actingAsTenant(string $tenantId, Closure $fn): mixed
{
    return DB::transaction(function () use ($tenantId, $fn): mixed {
        DB::selectOne('select set_config(?, ?, true)', ['app.tenant_id', $tenantId]);

        return $fn();
    });
}

/**
 * Same transaction-scoped pattern, additionally assuming one of the RLS
 * group roles first, mirroring the production request posture (SET LOCAL
 * ROLE plus SET LOCAL app.tenant_id, both dying with the transaction).
 * SET ROLE cannot take query parameters, so $role is interpolated; callers
 * pass the Rls class constants, never request input. A null $tenantId
 * leaves the setting untouched to probe deny-by-default. $userId is the
 * second SET LOCAL setting (app.user_id) the memberships_self_read policy
 * matches against (stage-03 plan, Data model); left unset unless a test
 * needs it.
 */
function actingAsRole(string $role, ?string $tenantId, Closure $fn, ?string $userId = null): mixed
{
    return DB::transaction(function () use ($role, $tenantId, $userId, $fn): mixed {
        DB::statement("set local role {$role}");

        if ($tenantId !== null) {
            DB::selectOne('select set_config(?, ?, true)', ['app.tenant_id', $tenantId]);
        }

        if ($userId !== null) {
            DB::selectOne('select set_config(?, ?, true)', ['app.user_id', $userId]);
        }

        return $fn();
    });
}
