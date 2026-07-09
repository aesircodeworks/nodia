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
