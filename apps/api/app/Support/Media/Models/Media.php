<?php

namespace App\Support\Media\Models;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

/**
 * The tenant-aware Media subclass stage-05c task 1 requires before any
 * collection can be attached to Event or Tenant (data-conventions
 * "Published migrations from third-party packages ... are adjusted for
 * UUID keys and non-null tenant_id"; ADR 014). HasUuids overrides key
 * generation to a v7 uuid, matching every other primary key in this
 * codebase, over the base package's auto-incrementing bigint id; this is
 * unrelated to the package's own `uuid` column (Spatie\MediaLibrary\
 * MediaCollections\Models\Concerns\HasUuid, still in force here), a
 * separate public identifier the URL and conversion machinery reads and
 * writes.
 *
 * tenant_id has no column in the base package's schema at all, so nothing
 * upstream ever sets it. Medialibrary constructs Media rows itself deep
 * inside FileAdder::toMediaCollection() with no call-site hook for extra
 * attributes other than withProperties()/withCustomProperties(), so
 * stamping happens here, once, on creating: filled from the request-scoped
 * TenantContext (App\Support\Tenancy) when the attribute is not already
 * set, and left alone otherwise, because Stage 5c task 5's tenant branding
 * logo upload runs under the platform posture (asPlatform(), system-design
 * 4.3) where TenantContext's own tenant is the platform sentinel, not the
 * tenant the logo belongs to; that call site passes the real tenant_id
 * explicitly via withProperties(['tenant_id' => ...]), which this hook
 * must not override. TenantContext::tenantId() itself throws when no
 * tenant transaction is active, which is what makes creation without any
 * active context a refusal rather than a silent NULL, satisfying media's
 * non-null tenant_id column and its RLS policy's WITH CHECK.
 */
class Media extends BaseMedia
{
    use HasUuids;

    protected static function booted(): void
    {
        static::creating(function (BaseMedia $media): void {
            if (blank($media->tenant_id)) {
                $media->tenant_id = app(TenantContext::class)->tenantId();
            }
        });
    }
}
