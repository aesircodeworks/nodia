<?php

use App\Identity\Capability;

/*
 * Locks the registry down (stage-03 plan, Data model): later stages
 * extend the enum, never repurpose an existing entry, so a change to this
 * test signals exactly that kind of accidental rename or removal.
 */

it('carries the stage-03 initial registry, no more and no less', function () {
    $values = array_map(fn (Capability $capability): string => $capability->value, Capability::cases());

    expect($values)->toBe([
        'roles.manage',
        'memberships.manage',
        'tenants.manage',
        'events.view',
        'events.manage',
        'events.publish',
        'orders.view',
        'orders.refund',
        'payouts.view',
        'checkin.scan',
    ]);
});

it('marks exactly orders.refund and payouts.view as financially privileged', function () {
    $privileged = array_map(
        fn (Capability $capability): string => $capability->value,
        array_values(array_filter(Capability::cases(), fn (Capability $capability): bool => $capability->isFinanciallyPrivileged())),
    );

    expect($privileged)->toBe(['orders.refund', 'payouts.view']);
});

it('marks every other capability as not financially privileged', function (Capability $capability) {
    expect($capability->isFinanciallyPrivileged())->toBeFalse();
})->with([
    Capability::RolesManage,
    Capability::MembershipsManage,
    Capability::TenantsManage,
    Capability::EventsView,
    Capability::EventsManage,
    Capability::EventsPublish,
    Capability::OrdersView,
    Capability::CheckinScan,
]);
