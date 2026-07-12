<?php

use App\CheckIn\Actions\CheckEventAssignment;
use App\CheckIn\Data\CheckEventAssignmentData;
use App\CheckIn\Models\CheckInAssignment;
use App\EventCatalog\Models\Event;
use App\Identity\Capability;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-09 plan, Authorization semantics paragraph and task breakdown
 * item 6: CheckEventAssignment is the CheckIn Action Orders' signing-key
 * endpoints call to authorize without importing CheckIn models or
 * querying check_in_assignments directly (boundary rule, system-design
 * 3.1). The assignment evaluation matrix: scan+assigned ok, scan
 * unassigned denied, manage unassigned ok, no capability denied.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->eventId = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId])->id,
    );

    $this->userId = app(TenantTransaction::class)->asPlatform(fn () => User::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('check_in_assignments')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('users')->where('id', $this->userId)->delete();
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

it('authorizes a caller holding checkin.scan who has an assignment row for the event', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        CheckInAssignment::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
            'user_id' => $this->userId,
        ]);

        $result = (app(CheckEventAssignment::class))(new CheckEventAssignmentData(
            userId: $this->userId,
            eventId: $this->eventId,
            capabilities: [Capability::CheckinScan->value],
        ));

        expect($result->authorized)->toBeTrue();
    });
});

it('denies a caller holding checkin.scan with no assignment row for the event', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $result = (app(CheckEventAssignment::class))(new CheckEventAssignmentData(
            userId: $this->userId,
            eventId: $this->eventId,
            capabilities: [Capability::CheckinScan->value],
        ));

        expect($result->authorized)->toBeFalse();
    });
});

it('authorizes a caller holding checkin.manage with no assignment row for the event', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $result = (app(CheckEventAssignment::class))(new CheckEventAssignmentData(
            userId: $this->userId,
            eventId: $this->eventId,
            capabilities: [Capability::CheckinManage->value],
        ));

        expect($result->authorized)->toBeTrue();
    });
});

it('denies a caller holding neither checkin.scan nor checkin.manage', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $result = (app(CheckEventAssignment::class))(new CheckEventAssignmentData(
            userId: $this->userId,
            eventId: $this->eventId,
            capabilities: ['events.view'],
        ));

        expect($result->authorized)->toBeFalse();
    });
});

it('authorizes a caller holding checkin.scan and checkin.manage together, assigned or not', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $result = (app(CheckEventAssignment::class))(new CheckEventAssignmentData(
            userId: $this->userId,
            eventId: $this->eventId,
            capabilities: [Capability::CheckinScan->value, Capability::CheckinManage->value],
        ));

        expect($result->authorized)->toBeTrue();
    });
});
