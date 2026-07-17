<?php

namespace App\EventCatalog\Http\Controllers;

use App\EventCatalog\Data\StorefrontEventData;
use App\EventCatalog\Data\StorefrontEventSearchQueryData;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Exceptions\EventNotFoundException;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Support\Search\EventSearcher;
use App\EventCatalog\Support\StorefrontLocaleNegotiator;
use App\Support\Tenancy\TenantContext;
use App\Tenancy\Actions\ResolveTenantLocaleSettings;
use App\Tenancy\Data\TenantLocaleSettingsData;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\LaravelData\PaginatedDataCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Host-resolved storefront read surface (stage-05a plan, task
 * breakdown item 10): tenant comes from ResolveTenantFromHost, so
 * tenant_isolation RLS already scopes every query to the resolved tenant
 * and no X-Tenant-Id or staff identity is involved. Only published events
 * are ever visible; drafts and canceled events are absent from the list
 * and return the same request.not_found a nonexistent id gets on detail,
 * so unpublished existence never leaks. Content is resolved in the
 * negotiated locale and the response carries a Content-Language header.
 */
class StorefrontEventController
{
    public function __construct(
        private readonly StorefrontLocaleNegotiator $negotiator,
        private readonly ResolveTenantLocaleSettings $resolveLocaleSettings,
        private readonly TenantContext $tenantContext,
        private readonly EventSearcher $searcher,
    ) {}

    public function index(Request $request, StorefrontEventSearchQueryData $query): Response
    {
        $settings = ($this->resolveLocaleSettings)($this->tenantContext->tenantId());
        $locale = $this->negotiate($request, $settings);

        $events = ($query->q === null ? $this->listPublished() : $this->search($query->q, $locale))
            ->appends($request->query());

        $collection = StorefrontEventData::collect(
            $events->through(fn (Event $event): StorefrontEventData => StorefrontEventData::fromModel($event, $locale, $settings->defaultLocale)),
            PaginatedDataCollection::class,
        );

        return $this->withContentLanguage($collection->toResponse($request), $locale);
    }

    public function show(string $event, Request $request): Response
    {
        $settings = ($this->resolveLocaleSettings)($this->tenantContext->tenantId());
        $locale = $this->negotiate($request, $settings);

        $model = Event::query()
            ->where('status', EventStatus::Published)
            ->whereKey($event)
            ->with(['ticketTypes', 'media'])
            ->first() ?? throw EventNotFoundException::forId($event);

        $data = StorefrontEventData::fromModel($model, $locale, $settings->defaultLocale);

        return $this->withContentLanguage($data->toResponse($request), $locale);
    }

    /**
     * @return LengthAwarePaginator<int, Event>
     */
    private function listPublished(): LengthAwarePaginator
    {
        return Event::query()
            ->where('status', EventStatus::Published)
            ->with(['ticketTypes', 'media'])
            ->orderBy('start_at')
            ->paginate();
    }

    /**
     * Delegates to the container-bound App\EventCatalog\Support\Search\
     * EventSearcher (stage-05c plan, task breakdown item 9; Endpoints
     * "Storefront search"), never PostgresEventSearcher directly
     * (tests/Architecture/SearchSeamTest.php), so a future Meilisearch
     * driver swap is a container-binding change only. The searcher's own
     * query selects only events.* (App\EventCatalog\Support\Search\
     * PostgresEventSearcher), so ticketTypes and media, which
     * StorefrontEventData::fromModel needs, are eager loaded here rather
     * than relied upon from the searcher's own eager loading.
     *
     * @return LengthAwarePaginator<int, Event>
     */
    private function search(string $query, string $locale): LengthAwarePaginator
    {
        $results = $this->searcher->search($query, $locale);
        $results->getCollection()->each(fn (Event $event): mixed => $event->loadMissing(['ticketTypes', 'media']));

        return $results;
    }

    private function negotiate(Request $request, TenantLocaleSettingsData $settings): string
    {
        $explicit = $request->query('locale');

        return $this->negotiator->negotiate(
            is_string($explicit) ? $explicit : null,
            $request->headers->get('Accept-Language'),
            $settings,
        );
    }

    private function withContentLanguage(Response $response, string $locale): Response
    {
        $response->headers->set('Content-Language', $locale);

        return $response;
    }
}
