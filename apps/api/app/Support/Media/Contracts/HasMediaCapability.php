<?php

namespace App\Support\Media\Contracts;

use App\Identity\Capability;

/**
 * Lets the shared, top-level DELETE /v1/media/{media} endpoint
 * (App\Http\Controllers\MediaController) resolve which capability guards
 * deleting a given media row without importing any bounded context's own
 * Model class (stage-05c plan, Endpoints: "Authorization delegates to the
 * owning model's policy: event media requires events.manage, tenant logo
 * requires tenants.manage"). ContextBoundariesTest forbids anything
 * outside a context from using that context's own Models namespace, so a
 * shared controller spanning two contexts' media owners (Event now,
 * Tenant in a later task) cannot switch on `$media->model_type` against
 * imported class names the way a same-context controller would; each
 * owning model instead answers for itself through this interface,
 * mirroring why RequireCapability itself (App\Http\Middleware) lives
 * outside every context despite gating routes in several of them.
 */
interface HasMediaCapability
{
    public function mediaManageCapability(): Capability;
}
