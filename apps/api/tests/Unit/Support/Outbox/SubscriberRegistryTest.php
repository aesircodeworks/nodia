<?php

declare(strict_types=1);

use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;
use App\Support\Outbox\SubscriberRegistry;
use Tests\Support\Outbox\FixtureDomainEvent;
use Tests\Support\Outbox\IdempotentTestSubscriber;

/*
 * Stage-04 plan Slice 2 unit: the subscriber registry rejects duplicate
 * names; routing is static in code (no config sniffing).
 */

beforeEach(function (): void {
    app()->forgetInstance(SubscriberRegistry::class);
});

afterEach(function (): void {
    app()->forgetInstance(SubscriberRegistry::class);
});

it('rejects duplicate subscriber names', function () {
    $registry = app(SubscriberRegistry::class);
    $handler = new IdempotentTestSubscriber;

    $registry->register(IdempotentTestSubscriber::NAME, [FixtureDomainEvent::TYPE], $handler);

    expect(fn () => $registry->register(
        IdempotentTestSubscriber::NAME,
        [FixtureDomainEvent::TYPE],
        new IdempotentTestSubscriber,
    ))->toThrow(LogicException::class, IdempotentTestSubscriber::NAME);
});

it('routes event types to the subscribers registered for them in registration order', function () {
    $registry = app(SubscriberRegistry::class);

    $first = new class implements OutboxSubscriber
    {
        public function handle(OutboxEvent $event): void {}
    };
    $second = new class implements OutboxSubscriber
    {
        public function handle(OutboxEvent $event): void {}
    };
    $other = new class implements OutboxSubscriber
    {
        public function handle(OutboxEvent $event): void {}
    };

    $registry->register('alpha', [FixtureDomainEvent::TYPE, 'OtherEvent'], $first);
    $registry->register('beta', [FixtureDomainEvent::TYPE], $second);
    $registry->register('gamma', ['OtherEvent'], $other);

    expect($registry->namesFor(FixtureDomainEvent::TYPE))->toBe(['alpha', 'beta'])
        ->and($registry->namesFor('OtherEvent'))->toBe(['alpha', 'gamma'])
        ->and($registry->namesFor('Unregistered'))->toBe([])
        ->and($registry->handler('alpha'))->toBe($first)
        ->and($registry->typesFor('alpha'))->toBe([FixtureDomainEvent::TYPE, 'OtherEvent'])
        ->and($registry->typesFor('beta'))->toBe([FixtureDomainEvent::TYPE])
        ->and($registry->isRegistered('beta'))->toBeTrue()
        ->and($registry->isRegistered('missing'))->toBeFalse()
        ->and($registry->names())->toBe(['alpha', 'beta', 'gamma']);
});

it('rejects typesFor for an unregistered subscriber', function () {
    expect(fn () => app(SubscriberRegistry::class)->typesFor('missing'))
        ->toThrow(LogicException::class, 'missing');
});

it('rejects a subscriber with an empty event type list', function () {
    expect(fn () => app(SubscriberRegistry::class)->register(
        'empty',
        [],
        new IdempotentTestSubscriber,
    ))->toThrow(LogicException::class, 'at least one event type');
});

it('starts empty so production has no accidental test routing', function () {
    expect(app(SubscriberRegistry::class)->names())->toBe([]);
});
