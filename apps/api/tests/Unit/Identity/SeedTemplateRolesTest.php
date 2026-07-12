<?php

use App\Identity\Actions\SeedTemplateRoles;
use App\Identity\Capability;
use App\Identity\Models\Role;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * The template seeder's idempotence (stage-03 plan, Task breakdown item
 * 4): the seed_template_roles migration already ran the action once as
 * part of MigratedDatabase's migrate:fresh, so calling it again here
 * proves repeated invocation neither duplicates rows nor throws, without
 * this test standing up its own migration harness. Runs against the real
 * Postgres test database (as PassportMigrationsTest does) under the
 * default, unrestricted connection: nothing here exercises RLS, only the
 * updateOrCreate idempotence.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

it('seeds exactly the five named template roles', function () {
    $names = Role::query()->whereNull('tenant_id')->orderBy('name')->pluck('name');

    expect($names->all())->toBe(['Box Office', 'Check-in Agent', 'Event Manager', 'Finance', 'Owner']);
});

it('does not duplicate rows when run a second time', function () {
    $before = Role::query()->whereNull('tenant_id')->count();

    app(SeedTemplateRoles::class)->handle();

    $after = Role::query()->whereNull('tenant_id')->count();

    expect($before)->toBe(5)
        ->and($after)->toBe(5);
});

it('leaves every template capability set unchanged across repeated invocations', function () {
    $before = Role::query()->whereNull('tenant_id')->orderBy('name')->pluck('capabilities', 'name');

    app(SeedTemplateRoles::class)->handle();

    $after = Role::query()->whereNull('tenant_id')->orderBy('name')->pluck('capabilities', 'name');

    expect($after->all())->toBe($before->all());
});

it('grants the Owner template every capability except tenants.manage', function () {
    $owner = Role::query()->whereNull('tenant_id')->where('name', 'Owner')->firstOrFail();

    $expected = array_values(array_map(
        fn (Capability $capability): string => $capability->value,
        array_filter(Capability::cases(), fn (Capability $capability): bool => $capability !== Capability::TenantsManage),
    ));

    expect($owner->capabilities)->toEqualCanonicalizing($expected);
});

it('grants the Event Manager template checkin.manage', function () {
    $eventManager = Role::query()->whereNull('tenant_id')->where('name', 'Event Manager')->firstOrFail();

    expect($eventManager->capabilities)->toContain(Capability::CheckinManage->value);
});

it('grants the Finance template every financially privileged capability', function () {
    $finance = Role::query()->whereNull('tenant_id')->where('name', 'Finance')->firstOrFail();

    expect($finance->capabilities)->toContain(
        Capability::OrdersRefund->value,
        Capability::PayoutsView->value,
        Capability::PayoutsManage->value,
    );
});
