<?php

use App\Identity\Events\CustomerAnonymized;
use App\Identity\Events\CustomerAnonymizedPayload;
use Illuminate\Support\Str;

/*
 * Stage-12 plan, Slice 1 Unit tests: "the payload Data class contains no
 * name or email field," the structural half of erasure's core guarantee
 * that retained, replayed outbox rows never carry the PII they erase
 * (event-conventions Payloads; system-design 9.1).
 */

it('declares only customerId and dataSubjectRequestId, no name or email property', function () {
    $class = new ReflectionClass(CustomerAnonymizedPayload::class);

    // Only properties declared on this class itself, not the internal
    // bookkeeping properties Spatie\LaravelData\Data carries (_additional,
    // _dataContext): those are implementation details of the base class,
    // never wire fields.
    $names = array_map(
        fn (ReflectionProperty $property): string => $property->getName(),
        array_filter(
            $class->getProperties(),
            fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === CustomerAnonymizedPayload::class,
        ),
    );

    expect($names)->toEqualCanonicalizing(['customerId', 'dataSubjectRequestId'])
        ->and($names)->not->toContain('name')
        ->and($names)->not->toContain('email');
});

it('serializes to snake_case identifiers only', function () {
    $payload = new CustomerAnonymizedPayload('customer-id', 'request-id');

    expect($payload->toArray())->toBe([
        'customer_id' => 'customer-id',
        'data_subject_request_id' => 'request-id',
    ]);
});

it('builds the envelope with the customer aggregate and the given tenant', function () {
    $tenantId = (string) Str::uuid7();
    $customerId = (string) Str::uuid7();
    $requestId = (string) Str::uuid7();

    $event = CustomerAnonymized::forCustomer($tenantId, $customerId, $requestId);

    expect($event->type())->toBe('CustomerAnonymized')
        ->and($event->tenantId())->toBe($tenantId)
        ->and($event->aggregateType())->toBe('customer')
        ->and($event->aggregateId())->toBe($customerId)
        ->and($event->payload()->customerId)->toBe($customerId)
        ->and($event->payload()->dataSubjectRequestId)->toBe($requestId);
});
