<?php

declare(strict_types=1);

use App\Identity\Enums\MembershipScope;
use App\Identity\Events\UserInvited;
use App\Identity\Events\UserInvitedPayload;
use App\Identity\Models\Membership;
use Illuminate\Support\Str;

it('builds the membership aggregate envelope from a membership', function () {
    $tenantId = Str::uuid7()->toString();
    $membershipId = Str::uuid7()->toString();
    $userId = Str::uuid7()->toString();
    $roleId = Str::uuid7()->toString();
    $invitedByUserId = Str::uuid7()->toString();

    $membership = new Membership([
        'user_id' => $userId,
        'tenant_id' => $tenantId,
        'role_id' => $roleId,
        'scope' => MembershipScope::Tenant,
    ]);
    $membership->id = $membershipId;

    $event = UserInvited::fromMembership($membership, $invitedByUserId);

    expect($event->type())->toBe('UserInvited')
        ->and($event->tenantId)->toBe($tenantId)
        ->and($event->aggregateType)->toBe('membership')
        ->and($event->aggregateId)->toBe($membershipId)
        ->and($event->payload)->toBeInstanceOf(UserInvitedPayload::class);
});

it('uses the sentinel platform tenant when the membership is pinned to it', function () {
    $sentinel = config()->string('tenancy.platform_tenant_id');
    $membershipId = Str::uuid7()->toString();

    $membership = new Membership([
        'user_id' => Str::uuid7()->toString(),
        'tenant_id' => $sentinel,
        'role_id' => Str::uuid7()->toString(),
        'scope' => MembershipScope::Platform,
    ]);
    $membership->id = $membershipId;

    $event = UserInvited::fromMembership($membership, Str::uuid7()->toString());

    expect($event->tenantId)->toBe($sentinel)
        ->and($event->payload->tenantId)->toBe($sentinel);
});

it('serializes the exact identifier field set in snake_case without email or name', function () {
    $tenantId = Str::uuid7()->toString();
    $membershipId = Str::uuid7()->toString();
    $userId = Str::uuid7()->toString();
    $roleId = Str::uuid7()->toString();
    $invitedByUserId = Str::uuid7()->toString();

    $payload = new UserInvitedPayload(
        $userId,
        $membershipId,
        $tenantId,
        $roleId,
        $invitedByUserId,
    );

    $array = $payload->toArray();

    expect($array)->toBe([
        'user_id' => $userId,
        'membership_id' => $membershipId,
        'tenant_id' => $tenantId,
        'role_id' => $roleId,
        'invited_by_user_id' => $invitedByUserId,
    ])
        ->and($array)->not->toHaveKey('email')
        ->and($array)->not->toHaveKey('name')
        ->and(array_keys($array))->not->toContain('email')
        ->and(array_keys($array))->not->toContain('name');
});
