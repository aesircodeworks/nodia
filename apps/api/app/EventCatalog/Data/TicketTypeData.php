<?php

namespace App\EventCatalog\Data;

use App\EventCatalog\Models\TicketType;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Admin surface response shape (stage-05a plan, Endpoints: "TicketTypeData
 * carries price as the {amount, currency} object via the Stage 1 Money
 * transformer; price_amount never appears bare on the wire"). max_per_customer
 * is stage-10's additive per-ticket-type purchase limit (Data model
 * "ticket_types.max_per_customer"); null means unlimited, including for
 * every ticket type that existed before this column landed.
 */
#[MapName(SnakeCaseMapper::class)]
class TicketTypeData extends Data
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $eventId,
        public string $name,
        public Money $price,
        public ?string $salesStart,
        public ?string $salesEnd,
        public bool $requiresSeat,
        public ?int $maxPerCustomer,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(TicketType $ticketType): self
    {
        return new self(
            $ticketType->id,
            $ticketType->tenant_id,
            $ticketType->event_id,
            $ticketType->name,
            $ticketType->price,
            self::formatNullableTimestamp($ticketType->sales_start),
            self::formatNullableTimestamp($ticketType->sales_end),
            $ticketType->requires_seat,
            $ticketType->max_per_customer,
            CarbonImmutable::instance($ticketType->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            CarbonImmutable::instance($ticketType->updated_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }

    private static function formatNullableTimestamp(?CarbonInterface $value): ?string
    {
        return $value === null ? null : CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
