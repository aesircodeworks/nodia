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
     * $userId, when given, additionally sets app.user_id for the
     * transaction's duration (stage-03 plan, Slice 3): the admin
     * resolution middleware needs it active before the membership lookup
     * that decides access runs, and memberships_self_read matches against
     * it independent of the tenant setting above.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function asTenant(string $tenantId, Closure $callback, ?string $userId = null): mixed
    {
        return $this->run(Rls::APP_ROLE, $tenantId, $callback, $userId);
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
     * The anonymous domain-resolution posture: a short transaction under
     * the narrow nodia_resolver role with no tenant setting and no
     * TenantContext entry, used to look a Host up in tenant_domains before
     * any tenant context exists. Rejected inside any open transaction
     * (not just tenant ones, which resolver lookups never set context
     * for), because SET LOCAL ROLE inside a savepoint survives the
     * savepoint's release and would bleed into the outer transaction.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function asDomainResolver(Closure $callback): mixed
    {
        if ($this->context->hasTenant() || DB::transactionLevel() > 0) {
            throw new LogicException('A transaction is already active; the domain resolver posture must run in its own transaction.');
        }

        return DB::transaction(function () use ($callback): mixed {
            DB::statement('set local role '.Rls::RESOLVER_ROLE);

            return $callback();
        });
    }

    /**
     * The self-only staff posture: nodia_app role with app.user_id set and
     * no app.tenant_id, used by GET /v1/me to list a caller's own
     * memberships across every tenant through memberships_self_read
     * without asserting any particular tenant (stage-03 plan, Slice 3:
     * "GET /v1/me now returns the caller's memberships through
     * memberships_self_read with app.user_id set by the auth layer"). No
     * TenantContext entry is made, matching asDomainResolver's precedent
     * for a posture that asserts no tenant, which also keeps a later
     * per-tenant asTenant() call (used to resolve each membership's role
     * name) from being rejected as a nested tenant transaction, so long as
     * it runs after this transaction has already committed rather than
     * from inside its callback (SET LOCAL inside a savepoint survives the
     * savepoint's release and would bleed into the outer transaction, the
     * same hazard asDomainResolver's own docblock records).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function asAuthenticatedStaff(string $userId, Closure $callback): mixed
    {
        if ($this->context->hasTenant() || DB::transactionLevel() > 0) {
            throw new LogicException('A transaction is already active; the authenticated-staff posture must run in its own transaction.');
        }

        return DB::transaction(function () use ($userId, $callback): mixed {
            DB::statement('set local role '.Rls::APP_ROLE);
            DB::selectOne('select set_config(?, ?, true)', ['app.user_id', $userId]);

            return $callback();
        });
    }

    /**
     * Elevates an already-open tenant transaction from nodia_app to
     * nodia_platform without leaving it (stage-03 plan, Slice 3:
     * "platform-scope membership plus a tenant header reaches the tenant
     * via the platform role"). The elevation can only be decided from
     * inside the transaction it elevates, since the membership lookup that
     * drives the decision itself needs the SET LOCAL role and tenant
     * setting already in place to run under RLS; a second SET LOCAL ROLE
     * statement mid-transaction is well-defined in Postgres and simply
     * takes effect for the remainder of the transaction.
     */
    public function elevateToPlatformRole(): void
    {
        if (! $this->context->hasTenant()) {
            throw new LogicException('No tenant transaction is active; elevateToPlatformRole() may only be called inside one.');
        }

        DB::statement('set local role '.Rls::PLATFORM_ROLE);
        $this->context->enter($this->context->tenantId(), Rls::PLATFORM_ROLE);
    }

    /**
     * Nesting is rejected because SET LOCAL inside a savepoint survives
     * the savepoint's release: an inner posture would silently bleed into
     * the remainder of the outer transaction. $userId, when given,
     * additionally sets app.user_id before the callback runs (Slice 3):
     * always before any other query, since the caller (the admin
     * resolution middleware) must not let a business query run inside
     * this transaction before its own membership validation, which reads
     * app.user_id, has completed.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function run(string $role, string $tenantId, Closure $callback, ?string $userId = null): mixed
    {
        if ($this->context->hasTenant()) {
            throw new LogicException('A tenant transaction is already active; nested tenant transactions are not supported.');
        }

        if (! Str::isUuid($tenantId)) {
            throw InvalidTenantIdException::for($tenantId);
        }

        return DB::transaction(function () use ($role, $tenantId, $callback, $userId): mixed {
            // SET LOCAL ROLE cannot take bindings; $role is always one of
            // the Rls class constants, never request input. set_config(...,
            // true) is the bindable equivalent of SET LOCAL for the setting.
            DB::statement("set local role {$role}");
            DB::selectOne('select set_config(?, ?, true)', ['app.tenant_id', $tenantId]);

            if ($userId !== null) {
                DB::selectOne('select set_config(?, ?, true)', ['app.user_id', $userId]);
            }

            $this->context->enter($tenantId, $role);

            try {
                return $callback();
            } finally {
                $this->context->clear();
            }
        });
    }
}
