<?php

namespace App\Identity\Authorization;

use App\Identity\Actions\ResolveActingMembership;
use App\Identity\Capability;
use App\Identity\Exceptions\MissingCapabilityException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Authorization always evaluates capability plus tenant context, never
 * role names (system-design 5.3, ADR 012): every ability ever checked in
 * this app is one of the Capability enum's values, and this class is the
 * single place that resolves whether the acting membership holds it.
 *
 * register() wires a Gate::before callback so any future Policy can use
 * the idiomatic Gate::allows()/$user->can() with a capability's wire value
 * as the ability name (task breakdown items 7 through 9 attach TenantPolicy,
 * RolePolicy, and MembershipPolicy this way); returning null for anything
 * that is not a known capability leaves Laravel's normal ability
 * resolution untouched. authorize() is the entry point controllers and
 * Actions call directly: it maps a denial to MissingCapabilityException
 * (missing_capability, 403) rather than Laravel's own AuthorizationException,
 * matching every other domain exception in this codebase's HasErrorCode
 * convention instead of introducing a second error-mapping path.
 */
final class CapabilityGate
{
    public function __construct(
        private readonly ResolveActingMembership $membership,
        private readonly TenantContext $context,
    ) {}

    public static function register(): void
    {
        Gate::before(function (Authenticatable $user, string $ability): ?bool {
            $capability = Capability::tryFrom($ability);

            if ($capability === null) {
                return null;
            }

            return app(self::class)->allowsFor($user, $capability);
        });
    }

    public function allowsFor(Authenticatable $user, Capability $capability): bool
    {
        if (! $this->context->hasTenant()) {
            return false;
        }

        $membership = $this->membership->forUser($user->getAuthIdentifier(), $this->context->tenantId());

        return $membership !== null && in_array($capability->value, $membership->role->capabilities, true);
    }

    public function authorize(Capability $capability): void
    {
        $user = Auth::guard('staff')->user();

        if ($user === null || ! $this->allowsFor($user, $capability)) {
            throw MissingCapabilityException::for($capability);
        }
    }
}
