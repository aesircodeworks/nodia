<?php

namespace App\EventCatalog\Models;

use App\EventCatalog\Data\AsyncPaymentPolicyData;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Exceptions\InvalidEventVenueConfigurationException;
use Database\Factories\EventCatalog\Models\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
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
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, HasTranslations, HasUuids;

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
