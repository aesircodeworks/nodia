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
 * Storefront ticket-type shape (stage-05a plan, Endpoints: StorefrontEvent
 * Data "embeds StorefrontTicketTypeData (name, price as {amount, currency},
 * sales window)"). price is the Stage 1 Money value object, serialized to
 * {amount, currency} by the globally registered transformer; no
 * requires_seat or availability field exists on the storefront surface
 * until Stage 6.
 */
#[MapName(SnakeCaseMapper::class)]
class StorefrontTicketTypeData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public Money $price,
        public ?string $salesStart,
        public ?string $salesEnd,
    ) {}

    public static function fromModel(TicketType $ticketType): self
    {
        return new self(
            $ticketType->id,
            $ticketType->name,
            $ticketType->price,
            self::formatNullableTimestamp($ticketType->sales_start),
            self::formatNullableTimestamp($ticketType->sales_end),
        );
    }

    private static function formatNullableTimestamp(?CarbonInterface $timestamp): ?string
    {
        return $timestamp === null
            ? null
            : CarbonImmutable::instance($timestamp)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
