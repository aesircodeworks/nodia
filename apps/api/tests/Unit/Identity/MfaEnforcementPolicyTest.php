<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Support\MfaEnforcementPolicy;

/*
 * Stage-03 plan, MFA enforcement paragraph: platform-scope is always
 * enforcing regardless of capabilities; tenant-scope is enforcing only
 * when its capability set intersects the financially privileged set
 * (orders.refund, payouts.view).
 */

it('always requires MFA for a platform-scope membership, even with no capabilities', function (): void {
    expect(MfaEnforcementPolicy::requires(MembershipScope::Platform, []))->toBeTrue();
});

it('requires MFA for a tenant-scope membership holding orders.refund', function (): void {
    expect(MfaEnforcementPolicy::requires(MembershipScope::Tenant, ['orders.refund']))->toBeTrue();
});

it('requires MFA for a tenant-scope membership holding payouts.view', function (): void {
    expect(MfaEnforcementPolicy::requires(MembershipScope::Tenant, ['payouts.view']))->toBeTrue();
});

it('does not require MFA for a tenant-scope membership with no financially privileged capability', function (): void {
    expect(MfaEnforcementPolicy::requires(MembershipScope::Tenant, ['events.view', 'orders.view']))->toBeFalse();
});

it('does not require MFA for a tenant-scope membership with no capabilities at all', function (): void {
    expect(MfaEnforcementPolicy::requires(MembershipScope::Tenant, []))->toBeFalse();
});
