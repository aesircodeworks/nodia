<?php

namespace App\CheckIn\Data;

use App\CheckIn\Models\CheckIn;
use Carbon\CarbonImmutable;

/**
 * One check-in's export facts (stage-11 plan, task 15/T12: the
 * `check_ins` export source). Plain internal class, never serialized to
 * the wire, mirroring App\Orders\Data\OrderExportRowData's own posture.
 * No money columns: a scan attempt carries no monetary fact of its own.
 * user_id and device_id are deliberately left off the CSV (kept off the
 * row shape entirely, not merely hidden from columns()): the export is
 * keyed on the check-in's own id, and its natural correlating
 * identifiers are event_id and ticket_id (which admission right was
 * scanned, for which event), not the scanning staff member or client
 * device, which are operational metadata rather than reporting facts.
 */
final class CheckInExportRowData
{
    public function __construct(
        public readonly string $id,
        public readonly string $eventId,
        public readonly string $ticketId,
        public readonly string $result,
        public readonly string $scannedAt,
    ) {}

    public static function fromModel(CheckIn $checkIn): self
    {
        return new self(
            $checkIn->id,
            $checkIn->event_id,
            $checkIn->ticket_id,
            $checkIn->result->value,
            CarbonImmutable::instance($checkIn->scanned_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
