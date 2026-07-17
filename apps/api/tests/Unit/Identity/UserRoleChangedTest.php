<?php

declare(strict_types=1);

use App\Identity\Enums\MembershipScope;
use App\Identity\Events\UserRoleChanged;
use App\Identity\Events\UserRoleChangedPayload;
use App\Identity\Models\Membership;
use Illuminate\Support\Str;

it('builds the membership aggregate envelope from a membership', function () {
    $tenantId = Str::uuid7()->toString();
    $membershipId = Str::uuid7()->toString();
    $userId = Str::uuid7()->toString();
    $previousRoleId = Str::uuid7()->toString();
    $newRoleId = Str::uuid7()->toString();
    $changedByUserId = Str::uuid7()->toString();

    $membership = new Membership([
        'user_id' => $userId,
        'tenant_id' => $tenantId,
        'role_id' => $newRoleId,
        'scope' => MembershipScope::Tenant,
    ]);
    $membership->id = $membershipId;

    $event = UserRoleChanged::fromMembership(
        $membership,
        $previousRoleId,
        $newRoleId,
        $changedByUserId,
    );

    expect($event->type())->toBe('UserRoleChanged')
        ->and($event->tenantId)->toBe($tenantId)
        ->and($event->aggregateType)->toBe('membership')
        ->and($event->aggregateId)->toBe($membershipId)
        ->and($event->payload)->toBeInstanceOf(UserRoleChangedPayload::class);
});

it('uses the sentinel platform tenant when the membership is pinned to it', function () {
    $sentinel = config()->string('tenancy.platform_tenant_id');
    $membershipId = Str::uuid7()->toString();
    $previousRoleId = Str::uuid7()->toString();
    $newRoleId = Str::uuid7()->toString();

    $membership = new Membership([
        'user_id' => Str::uuid7()->toString(),
        'tenant_id' => $sentinel,
        'role_id' => $newRoleId,
        'scope' => MembershipScope::Platform,
    ]);
    $membership->id = $membershipId;

    $event = UserRoleChanged::fromMembership(
        $membership,
        $previousRoleId,
        $newRoleId,
        Str::uuid7()->toString(),
    );

    expect($event->tenantId)->toBe($sentinel);
});

it('serializes the exact identifier field set in snake_case without email or name', function () {
    $membershipId = Str::uuid7()->toString();
    $userId = Str::uuid7()->toString();
    $previousRoleId = Str::uuid7()->toString();
    $newRoleId = Str::uuid7()->toString();
    $changedByUserId = Str::uuid7()->toString();

    $payload = new UserRoleChangedPayload(
        $membershipId,
        $userId,
        $previousRoleId,
        $newRoleId,
        $changedByUserId,
    );

    $array = $payload->toArray();

    expect($array)->toBe([
        'membership_id' => $membershipId,
        'user_id' => $userId,
        'previous_role_id' => $previousRoleId,
        'new_role_id' => $newRoleId,
        'changed_by_user_id' => $changedByUserId,
    ])
        ->and($array)->not->toHaveKey('email')
        ->and($array)->not->toHaveKey('name')
        ->and(array_keys($array))->not->toContain('email')
        ->and(array_keys($array))->not->toContain('name');
});
