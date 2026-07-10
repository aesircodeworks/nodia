<?php

use App\EventCatalog\Enums\EventStatus;

/*
 * Stage-05a plan, task breakdown item 4: the authoritative list of
 * events.status values, and the DEFAULT the creating migration ships
 * (draft).
 */

it('has exactly the three lifecycle cases in draft-published-canceled order', function () {
    expect(array_map(fn (EventStatus $case) => $case->value, EventStatus::cases()))
        ->toBe(['draft', 'published', 'canceled']);
});

it('backs each case with its stable snake_case wire value', function () {
    expect(EventStatus::Draft->value)->toBe('draft')
        ->and(EventStatus::Published->value)->toBe('published')
        ->and(EventStatus::Canceled->value)->toBe('canceled');
});

it('resolves every wire value back to its case via tryFrom', function () {
    expect(EventStatus::tryFrom('draft'))->toBe(EventStatus::Draft)
        ->and(EventStatus::tryFrom('published'))->toBe(EventStatus::Published)
        ->and(EventStatus::tryFrom('canceled'))->toBe(EventStatus::Canceled)
        ->and(EventStatus::tryFrom('unknown'))->toBeNull();
});
