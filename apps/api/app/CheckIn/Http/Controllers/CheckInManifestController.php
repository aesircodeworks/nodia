<?php

namespace App\CheckIn\Http\Controllers;

use App\CheckIn\Actions\BuildManifest;
use App\CheckIn\Actions\CheckEventAssignment;
use App\CheckIn\Data\CheckEventAssignmentData;
use App\CheckIn\Data\ManifestEntryData;
use App\CheckIn\Exceptions\CheckinNotAssignedException;
use App\CheckIn\Exceptions\ManifestEventNotFoundException;
use App\EventCatalog\Actions\CheckEventExists;
use App\Identity\Actions\ResolveActingCapabilities;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\QueryBuilder\Exceptions\InvalidFilterQuery;

/**
 * GET /v1/events/{event}/check-in-manifest (stage-09 plan, Endpoints):
 * authorizes through checkin.scan plus an assignment row for the event,
 * or checkin.manage as a bypass, evaluated by CheckEventAssignment the
 * same way App\Orders\Http\Controllers\SigningKeyController's GET does,
 * so this controller never queries check_in_assignments directly.
 * Cursor-paginated over ticket id ascending, the manifest's own
 * deterministic order (BuildManifest and the underlying
 * App\Orders\Actions\ListEventTickets both already sort this way). The
 * data source is an in-memory Collection<ManifestEntryData>, not an
 * Eloquent query, so pagination is built by hand against Laravel's own
 * CursorPaginator rather than QueryBuilder::cursorPaginate().
 */
class CheckInManifestController
{
    private const ALLOWED_FILTERS = ['updated_since'];

    public function __construct(
        private readonly CheckEventExists $checkEventExists,
        private readonly ResolveActingCapabilities $resolveCapabilities,
        private readonly CheckEventAssignment $checkEventAssignment,
        private readonly BuildManifest $buildManifest,
    ) {}

    public function index(string $event, Request $request): CursorPaginatedDataCollection
    {
        $eventId = $this->eventIdOrFail($event);
        $this->authorizeForEvent($request, $eventId);

        $filters = (array) $request->query('filter', []);
        $unknown = array_diff(array_keys($filters), self::ALLOWED_FILTERS);

        if ($unknown !== []) {
            throw InvalidFilterQuery::filtersNotAllowed(
                collect($unknown),
                collect(self::ALLOWED_FILTERS),
            );
        }

        $updatedSince = $filters['updated_since'] ?? null;

        $entries = ($this->buildManifest)($eventId, $updatedSince === null ? null : (string) $updatedSince);

        $perPage = min($request->integer('per_page', 15), 100);
        $cursor = Cursor::fromEncoded($request->query('cursor'));

        $paginator = new CursorPaginator(
            $this->windowFor($entries, $cursor, $perPage),
            $perPage,
            $cursor,
            [
                'path' => $request->url(),
                'query' => $request->query(),
                'parameters' => ['ticketId'],
            ],
        );

        return ManifestEntryData::collect($paginator, CursorPaginatedDataCollection::class);
    }

    /**
     * @param  Collection<int, ManifestEntryData>  $entries  ascending by ticketId
     * @return Collection<int, ManifestEntryData>
     */
    private function windowFor(Collection $entries, ?Cursor $cursor, int $perPage): Collection
    {
        if ($cursor === null) {
            return $entries->take($perPage + 1)->values();
        }

        $lastId = $cursor->parameter('ticketId');

        if ($cursor->pointsToPreviousItems()) {
            return $entries
                ->filter(fn (ManifestEntryData $entry): bool => $entry->ticketId < $lastId)
                ->reverse()
                ->take($perPage + 1)
                ->values();
        }

        return $entries
            ->filter(fn (ManifestEntryData $entry): bool => $entry->ticketId > $lastId)
            ->take($perPage + 1)
            ->values();
    }

    /**
     * A well-formed but nonexistent or foreign-tenant (via RLS) event id
     * renders event_not_found, mirroring SigningKeyController's own
     * eventIdOrFail precedent.
     */
    private function eventIdOrFail(string $eventId): string
    {
        if (! ($this->checkEventExists)($eventId)) {
            throw ManifestEventNotFoundException::forId($eventId);
        }

        return $eventId;
    }

    private function authorizeForEvent(Request $request, string $eventId): void
    {
        $userId = $request->user('staff')?->getAuthIdentifier();
        $capabilities = $userId === null ? [] : ($this->resolveCapabilities)($userId);

        $result = ($this->checkEventAssignment)(new CheckEventAssignmentData(
            userId: (string) $userId,
            eventId: $eventId,
            capabilities: $capabilities,
        ));

        if (! $result->authorized) {
            throw CheckinNotAssignedException::forEvent($eventId);
        }
    }
}
