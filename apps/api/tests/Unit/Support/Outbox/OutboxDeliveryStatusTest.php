<?php

declare(strict_types=1);

use App\Support\Outbox\Enums\OutboxDeliveryStatus;

/*
 * Stage-04 plan task 6: outbox_deliveries.status is enum-backed with the
 * two states named in system-design 9.2 / the stage Data model.
 */

it('backs the pending and processed wire values', function () {
    expect(OutboxDeliveryStatus::Pending->value)->toBe('pending')
        ->and(OutboxDeliveryStatus::Processed->value)->toBe('processed');
});

it('enumerates only pending and processed', function () {
    expect(array_map(
        static fn (OutboxDeliveryStatus $case): string => $case->value,
        OutboxDeliveryStatus::cases(),
    ))->toBe(['pending', 'processed']);
});
