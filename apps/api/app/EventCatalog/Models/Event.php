<?php

namespace App\EventCatalog\Models;

use App\EventCatalog\Data\AsyncPaymentPolicyData;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Exceptions\InvalidEventVenueConfigurationException;
use App\Identity\Capability;
use App\Support\Media\Contracts\HasMediaCapability;
use App\Support\Media\ImageMediaCollections;
use Database\Factories\EventCatalog\Models\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;
use Spatie\Translatable\HasTranslations;

/**
 * The publishable catalog entity (stage-05a plan, Data model). name and
 * description are locale-keyed jsonb columns managed entirely by
 * Spatie\Translatable\HasTranslations (never added to casts() as 'array':
 * the trait handles JSON encoding itself, and double-casting breaks it,
 * per the package's own documented pitfall). venue_id is null exactly when
 * is_virtual is true and virtual_event_url is set, and vice versa,
 * enforced here as an application-level backstop
 * (assertVenueOrUrlInvariant, wired into the saving hook, mirroring
 * App\Identity\Models\Membership::assertScopeInvariant's precedent) and
 * structurally by the events_venue_or_url CHECK the creating migration
 * ships.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $venue_id
 * @property string|null $seat_map_id
 * @property EventStatus $status
 * @property array<string, string> $name
 * @property array<string, string> $description
 * @property Carbon $start_at
 * @property Carbon $end_at
 * @property string $timezone
 * @property bool $is_virtual
 * @property string|null $virtual_event_url
 * @property AsyncPaymentPolicyData $async_payment_policy
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'venue_id',
    'seat_map_id',
    'status',
    'name',
    'description',
    'start_at',
    'end_at',
    'timezone',
    'is_virtual',
    'virtual_event_url',
    'async_payment_policy',
])]
class Event extends Model implements HasMedia, HasMediaCapability
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, HasTranslations, HasUuids, InteractsWithMedia;

    /** @var list<string> */
    public $translatable = ['name', 'description'];

    protected static function booted(): void
    {
        static::saving(function (self $event): void {
            self::assertVenueOrUrlInvariant($event->is_virtual, $event->venue_id, $event->virtual_event_url);
        });
    }

    /**
     * Pulled out as a pure, DB-free static so it is unit-testable on its
     * own and so App\EventCatalog\Actions\CreateEvent/UpdateEvent (a later
     * task) inherit the guarantee automatically by going through the
     * model's saving hook, without repeating the check, mirroring
     * Membership::assertScopeInvariant's precedent (stage-03 task-04
     * journal).
     */
    public static function assertVenueOrUrlInvariant(bool $isVirtual, ?string $venueId, ?string $virtualEventUrl): void
    {
        $satisfied = $isVirtual
            ? ($venueId === null && $virtualEventUrl !== null)
            : ($venueId !== null && $virtualEventUrl === null);

        if (! $satisfied) {
            throw InvalidEventVenueConfigurationException::forCombination($isVirtual, $venueId, $virtualEventUrl);
        }
    }

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * @return HasMany<TicketType, $this>
     */
    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class);
    }

    /**
     * cover is single-file: re-uploading replaces the existing file
     * (stage-05c plan, Data model: "cover (single file, replaced on
     * re-upload)"); gallery accepts multiple, ordered by medialibrary's
     * own order_column. Both share the same accepted mime types, factored
     * out to App\Support\Media\ImageMediaCollections rather than
     * repeated here (stage-05c plan: "same accepted types" as the later
     * tenant logo collection).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('cover')
            ->singleFile()
            ->acceptsMimeTypes(ImageMediaCollections::ACCEPTED_MIME_TYPES);

        $this->addMediaCollection('gallery')
            ->acceptsMimeTypes(ImageMediaCollections::ACCEPTED_MIME_TYPES);
    }

    /**
     * thumb, card, and hero, queued on the dedicated Horizon queue,
     * factored out to App\Support\Media\ImageMediaCollections since
     * Tenant's logo collection (a later task) registers the identical
     * set (stage-05c plan, Data model: "Conversions: thumb, card, hero").
     */
    public function registerMediaConversions(?SpatieMedia $media = null): void
    {
        ImageMediaCollections::registerConversions($this);
    }

    /**
     * Event media mutations gate on events.manage, the same capability
     * every other event mutation already uses (stage-05c plan, Endpoints:
     * "Capability events.manage via the EventCatalog policy").
     */
    public function mediaManageCapability(): Capability
    {
        return Capability::EventsManage;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'is_virtual' => 'boolean',
            'async_payment_policy' => AsyncPaymentPolicyData::class,
        ];
    }
}
