<?php

use App\Identity\Capability;
use App\Identity\Exceptions\UnknownCapabilityException;
use App\Identity\Models\Role;

/*
 * Every stored capability must be a real entry in the Capability registry
 * (stage-03 plan, Roles and memberships endpoint table: unknown_capability
 * on task breakdown item 8). Pure and DB-free: Role::assertKnownCapabilities()
 * is exercised directly rather than through a real save, mirroring
 * MembershipScopeInvariantTest's precedent (task-04 journal) for
 * Membership::assertScopeInvariant.
 */

it('allows an empty capability list', function () {
    Role::assertKnownCapabilities([]);
})->throwsNoExceptions();

it('allows every real capability value', function () {
    Role::assertKnownCapabilities(array_map(fn (Capability $c) => $c->value, Capability::cases()));
})->throwsNoExceptions();

it('rejects a capability string outside the registry', function () {
    expect(fn () => Role::assertKnownCapabilities([Capability::EventsView->value, 'events.destroy']))
        ->toThrow(UnknownCapabilityException::class, 'events.destroy');
});
