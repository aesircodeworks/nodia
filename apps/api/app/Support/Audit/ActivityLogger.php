<?php

namespace App\Support\Audit;

use App\Support\Audit\Models\ActivityLogEntry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * The shared recording primitive of system-design 14.2's audit trail,
 * closing the seam Stage 2's PlatformRoleAudit and this stage's
 * activity_log migration (task-14) both left open. Deliberately does none
 * of its own SET LOCAL or transaction work, the same precedent
 * ResolveActingMembership and ResolveTenantAccess already established
 * (stage-03 task-05/06 journals): every call site already runs inside a
 * TenantTransaction posture (asTenant, asPlatform, or an elevated admin
 * transaction), and this class only ever reads that ambient TenantContext
 * to decide the row's tenant_id, never opens a posture of its own.
 *
 * Every recorded entry carries a platform_scope property reflecting
 * TenantContext::isPlatform() at the moment of the call, unconditionally:
 * this is what "flagged as platform-scope use" (stage-03 plan, Slice 7)
 * means in practice, whether the posture is the platform group's own
 * sentinel-tenant transaction or a tenant-scope admin transaction elevated
 * mid-request by ResolveTenantAccess's platform-scope fallback
 * (TenantTransaction::elevateToPlatformRole, stage-03 task-05). The
 * caller never has to remember to set this flag itself.
 */
final readonly class ActivityLogger
{
    public function __construct(private TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        string $description,
        ?Model $causer = null,
        ?string $event = null,
        array $properties = [],
    ): ActivityLogEntry {
        $entry = new ActivityLogEntry([
            'tenant_id' => $this->context->tenantId(),
            'log_name' => 'default',
            'event' => $event,
            'description' => $description,
            'properties' => [...$properties, 'platform_scope' => $this->context->isPlatform()],
        ]);

        if ($causer !== null) {
            $entry->causer_type = $causer->getMorphClass();
            $entry->causer_id = $causer->getKey();
        }

        $entry->save();

        return $entry;
    }
}
