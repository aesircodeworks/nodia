<?php

namespace App\CheckIn\Data;

use App\CheckIn\Enums\CheckInResult;
use App\CheckIn\Models\CheckIn;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * POST /v1/check-ins response body (stage-09 plan, Endpoints "POST
 * /v1/check-ins"), 201 for a fresh scan and 200 for a replay of the
 * same (device_id, client_scan_id) pair returning the original result.
 */
#[MapName(SnakeCaseMapper::class)]
class CheckInResultData extends Data
{
    public function __construct(
        public string $checkInId,
        public string $ticketId,
        public string $eventId,
        public CheckInResult $result,
        public string $scannedAt,
        public string $syncedAt,
    ) {}

    public static function fromModel(CheckIn $checkIn): self
    {
        return new self(
            checkInId: $checkIn->id,
            ticketId: $checkIn->ticket_id,
            eventId: $checkIn->event_id,
            result: $checkIn->result,
            scannedAt: self::format($checkIn->scanned_at),
            syncedAt: self::format($checkIn->synced_at),
        );
    }

    private static function format(mixed $timestamp): string
    {
        return CarbonImmutable::instance($timestamp)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
