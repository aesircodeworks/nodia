<?php

namespace App\Http\Controllers;

use App\Identity\Authorization\CapabilityGate;
use App\Support\Media\Actions\DeleteMedia;
use App\Support\Media\Contracts\HasMediaCapability;
use App\Support\Media\Exceptions\MediaNotFoundException;
use App\Support\Media\Models\Media;
use Illuminate\Http\Response;
use LogicException;

/**
 * DELETE /v1/media/{media} (stage-05c plan, Endpoints): a top-level
 * resource per api-conventions nesting rules, and the one route in this
 * stage that a single bounded context cannot own outright, since a media
 * row's owning model is Event today and will be Tenant from a later task
 * on (system-design 8.1 places both in the same polymorphic table).
 * Mounted from routes/api.php, the same home HealthController already
 * uses for a route with no single owning context, rather than either
 * EventCatalog's or Tenancy's own Http\Controllers (ContextBoundariesTest
 * forbids any other namespace from using a context's own Http classes,
 * and the reverse: this controller must not import either context's
 * Model classes, which is exactly why HasMediaCapability exists).
 *
 * The tenant_isolation RLS policy on media already makes another
 * tenant's row invisible to a plain find(), so a foreign-tenant or
 * genuinely unknown id renders the same request.not_found problem,
 * mirroring every other *NotFoundException in this codebase. Capability
 * authorization is resolved dynamically from the media's own owning
 * model rather than a static RequireCapability middleware parameter,
 * since which capability applies (events.manage today, tenants.manage
 * once Tenant media exists) depends on that owner, not on the route
 * itself; CapabilityGate::authorize() is the same entry point
 * RequireCapability delegates to, so the missing_capability failure mode
 * is identical either way.
 */
final class MediaController extends Controller
{
    public function __construct(private readonly CapabilityGate $gate) {}

    public function destroy(string $media, DeleteMedia $deleteMedia): Response
    {
        $model = Media::query()->find($media) ?? throw MediaNotFoundException::forId($media);

        $owner = $model->model;

        if (! $owner instanceof HasMediaCapability) {
            // Unreachable while every registered collection's owner
            // implements HasMediaCapability (Event now, Tenant from a
            // later task): a defensive guard, not a codepath any current
            // collection can reach.
            throw new LogicException(sprintf('%s does not implement HasMediaCapability.', $owner::class));
        }

        $this->gate->authorize($owner->mediaManageCapability());

        $deleteMedia($model);

        return response()->noContent();
    }
}
