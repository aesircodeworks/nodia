<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\AsyncPaymentPolicyData;
use App\EventCatalog\Data\CreateEventData;
use App\EventCatalog\Data\EventData;
use App\EventCatalog\Data\OnSalePolicyData;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Events\EventCreated;
use App\EventCatalog\Models\Event;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Tenancy\TenantContext;
use Spatie\LaravelData\Optional;

/**
 * POST /v1/events (stage-05a plan, task breakdown item 5). The whole
 * admin request already runs inside one database transaction
 * (App\Tenancy\Http\Middleware\ResolveTenantFromHeader wraps the entire
 * handler in TenantTransaction::asTenant()), so this Action needs no
 * transaction of its own, mirroring App\EventCatalog\Actions\CreateVenue.
 * EventCreated is recorded into the outbox in that same transaction
 * (stage-04 plan, Slice 6).
 */
final class CreateEvent
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OutboxRecorder $outbox,
    ) {}

    public function __invoke(CreateEventData $data): EventData
    {
        $event = Event::create([
            'tenant_id' => $this->tenantContext->tenantId(),
            // Set explicitly rather than relying on the events.status
            // column DEFAULT: Event::create() does not re-fetch the row
            // after insert (no auto-increment key), so a column left out
            // of this array stays null on the in-memory model until an
            // explicit refresh (task-03 journal, EventModelTest's own
            // ->fresh() workaround for the identical situation).
            'status' => EventStatus::Draft,
            'venue_id' => $data->venueId,
            'name' => $data->name,
            'description' => $data->description,
            'start_at' => $data->startAt,
            'end_at' => $data->endAt,
            'timezone' => $data->timezone,
            'is_virtual' => $data->isVirtual,
            'virtual_event_url' => $data->virtualEventUrl,
            'async_payment_policy' => $data->asyncPaymentPolicy instanceof Optional
                ? new AsyncPaymentPolicyData
                : $data->asyncPaymentPolicy,
            'on_sale_policy' => $data->onSalePolicy instanceof Optional
                ? new OnSalePolicyData
                : $data->onSalePolicy,
        ]);

        $this->outbox->record(EventCreated::fromEvent($event));

        return EventData::fromModel($event);
    }
}
