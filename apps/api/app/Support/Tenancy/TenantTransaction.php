<?php

namespace App\Support\Tenancy;

use App\Support\Database\Rls;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * The request transaction wrapper of system-design 4.1: every tenant-aware
 * handler runs inside a transaction that begins with SET LOCAL ROLE and
 * SET LOCAL app.tenant_id. Both settings die with the transaction, which
 * keeps the pattern safe under Octane worker reuse and transaction
 * pooling; the request-scoped TenantContext mirrors them for application
 * code and is cleared before the transaction ends, so neither the
 * connection nor the container carries posture into the next request.
 */
final readonly class TenantTransaction
{
    public function __construct(private TenantContext $context) {}

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function asTenant(string $tenantId, Closure $callback): mixed
    {
        return $this->run(Rls::APP_ROLE, $tenantId, $callback);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function asPlatform(Closure $callback): mixed
    {
        return $this->run(Rls::PLATFORM_ROLE, config()->string('tenancy.platform_tenant_id'), $callback);
    }

    /**
     * Nesting is rejected because SET LOCAL inside a savepoint survives
     * the savepoint's release: an inner posture would silently bleed into
     * the remainder of the outer transaction.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function run(string $role, string $tenantId, Closure $callback): mixed
    {
        if ($this->context->hasTenant()) {
            throw new LogicException('A tenant transaction is already active; nested tenant transactions are not supported.');
        }

        if (! Str::isUuid($tenantId)) {
            throw InvalidTenantIdException::for($tenantId);
        }

        return DB::transaction(function () use ($role, $tenantId, $callback): mixed {
            // SET LOCAL ROLE cannot take bindings; $role is always one of
            // the Rls class constants, never request input. set_config(...,
            // true) is the bindable equivalent of SET LOCAL for the setting.
            DB::statement("set local role {$role}");
            DB::selectOne('select set_config(?, ?, true)', ['app.tenant_id', $tenantId]);

            $this->context->enter($tenantId, $role);

            try {
                return $callback();
            } finally {
                $this->context->clear();
            }
        });
    }
}
