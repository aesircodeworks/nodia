<?php

declare(strict_types=1);

use App\Identity\Events\CustomerRegistered;
use App\Identity\Events\CustomerRegisteredPayload;
use App\Identity\Models\Customer;
use Illuminate\Support\Str;

it('builds the customer aggregate envelope from a customer', function () {
    $tenantId = Str::uuid7()->toString();
    $customerId = Str::uuid7()->toString();

    $customer = new Customer([
        'tenant_id' => $tenantId,
        'email' => 'guest@example.com',
        'name' => 'Guest',
        'password' => null,
        'locale' => 'en',
    ]);
    $customer->id = $customerId;

    $event = CustomerRegistered::fromCustomer($customer);

    expect($event->type())->toBe('CustomerRegistered')
        ->and($event->tenantId)->toBe($tenantId)
        ->and($event->aggregateType)->toBe('customer')
        ->and($event->aggregateId)->toBe($customerId)
        ->and($event->payload)->toBeInstanceOf(CustomerRegisteredPayload::class)
        ->and($event->payload->customerId)->toBe($customerId)
        ->and($event->payload->isGuest)->toBeTrue();
});

it('marks is_guest false when the customer has a password', function () {
    $customer = new Customer([
        'tenant_id' => Str::uuid7()->toString(),
        'email' => 'registered@example.com',
        'name' => 'Registered',
        'password' => 'hashed-or-plain-present',
        'locale' => 'en',
    ]);
    $customer->id = Str::uuid7()->toString();

    $event = CustomerRegistered::fromCustomer($customer);

    expect($event->payload->isGuest)->toBeFalse();
});

it('serializes the exact identifier field set in snake_case without email or name', function () {
    $customerId = Str::uuid7()->toString();

    $payload = new CustomerRegisteredPayload($customerId, true);

    $array = $payload->toArray();

    expect($array)->toBe([
        'customer_id' => $customerId,
        'is_guest' => true,
    ])
        ->and($array)->not->toHaveKey('email')
        ->and($array)->not->toHaveKey('name')
        ->and(array_keys($array))->not->toContain('email')
        ->and(array_keys($array))->not->toContain('name');
});
