<?php

use App\CheckIn\Models\CheckInAssignment;
use App\EventCatalog\Models\Event;
use App\Identity\Capability;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-09 plan, Endpoints "Check-in assignments" and Slice 6: GET
 * (page-paginated), POST, and DELETE /v1/check-in-assignments/{assignment},
 * all behind checkin.manage and activity-logged.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->eventId = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId])->id,
    );

    $this->member = app(TenantTransaction::class)->asPlatform(fn () => User::factory()->create());

    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $role = Role::factory()->create([
            'tenant_id' => $this->tenantId,
            'capabilities' => [Capability::CheckinScan->value],
        ]);
        Membership::factory()->create([
            'user_id' => $this->member->id,
            'tenant_id' => $this->tenantId,
            'role_id' => $role->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    $this->headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CheckinManage),
        'X-Tenant-Id' => $this->tenantId,
    ];
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('check_in_assignments')->where('tenant_id', $this->tenantId)->delete();
        DB::table('memberships')->where('tenant_id', $this->tenantId)->delete();
        DB::table('roles')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );

    User::query()->delete();
});

it('creates an assignment and returns 201 with the contract shape', function (): void {
    $response = $this->postJson(
        '/v1/events/'.$this->eventId.'/check-in-assignments',
        ['user_id' => $this->member->id],
        $this->headers,
    );

    $response->assertStatus(201)->assertConformsToOpenApi();
    $response->assertJsonPath('event_id', $this->eventId);
    $response->assertJsonPath('user_id', $this->member->id);
    expect($response->json('id'))->not->toBeEmpty();
});

it('lists assignments for an event, page-paginated', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        CheckInAssignment::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
            'user_id' => $this->member->id,
        ]);
    });

    $response = $this->getJson('/v1/events/'.$this->eventId.'/check-in-assignments', $this->headers);

    $response->assertStatus(200)->assertConformsToOpenApi();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('meta'))->not->toBeEmpty();
    expect($response->json('links'))->not->toBeEmpty();
});

it('rejects assigning a user with no membership in the tenant', function (): void {
    $outsider = app(TenantTransaction::class)->asPlatform(fn () => User::factory()->create());

    $response = $this->postJson(
        '/v1/events/'.$this->eventId.'/check-in-assignments',
        ['user_id' => $outsider->id],
        $this->headers,
    );

    $response->assertStatus(422)->assertConformsToOpenApi();
    $response->assertJsonPath('code', 'user_not_member');
});

it('rejects a duplicate assignment with 409 already_assigned', function (): void {
    $this->postJson(
        '/v1/events/'.$this->eventId.'/check-in-assignments',
        ['user_id' => $this->member->id],
        $this->headers,
    )->assertStatus(201);

    $response = $this->postJson(
        '/v1/events/'.$this->eventId.'/check-in-assignments',
        ['user_id' => $this->member->id],
        $this->headers,
    );

    $response->assertStatus(409)->assertConformsToOpenApi();
    $response->assertJsonPath('code', 'already_assigned');
});

it('renders event_not_found for an unknown event id on GET and POST', function (): void {
    $unknownEvent = (string) Str::uuid7();

    $this->getJson('/v1/events/'.$unknownEvent.'/check-in-assignments', $this->headers)
        ->assertStatus(404)
        ->assertJsonPath('code', 'event_not_found');

    $this->postJson(
        '/v1/events/'.$unknownEvent.'/check-in-assignments',
        ['user_id' => $this->member->id],
        $this->headers,
    )->assertStatus(404)->assertJsonPath('code', 'event_not_found');
});

it('deletes an assignment and returns 204', function (): void {
    $assignmentId = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => CheckInAssignment::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
            'user_id' => $this->member->id,
        ])->id,
    );

    $response = $this->deleteJson('/v1/check-in-assignments/'.$assignmentId, [], $this->headers);
    $response->assertStatus(204);

    $remaining = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => CheckInAssignment::query()->whereKey($assignmentId)->count(),
    );

    expect($remaining)->toBe(0);
});

it('renders assignment_not_found for an unknown assignment id on DELETE', function (): void {
    $response = $this->deleteJson('/v1/check-in-assignments/'.Str::uuid7(), [], $this->headers);

    $response->assertStatus(404)->assertConformsToOpenApi();
    $response->assertJsonPath('code', 'assignment_not_found');
});

it('requires the checkin.manage capability', function (): void {
    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CheckinScan),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $this->getJson('/v1/events/'.$this->eventId.'/check-in-assignments', $headers)->assertStatus(403);
    $this->postJson('/v1/events/'.$this->eventId.'/check-in-assignments', ['user_id' => $this->member->id], $headers)->assertStatus(403);
});

it('records an activity log entry for POST and DELETE', function (): void {
    $assignmentId = $this->postJson(
        '/v1/events/'.$this->eventId.'/check-in-assignments',
        ['user_id' => $this->member->id],
        $this->headers,
    )->json('id');

    $this->deleteJson('/v1/check-in-assignments/'.$assignmentId, [], $this->headers)->assertStatus(204);

    $entries = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('activity_log')->where('tenant_id', $this->tenantId)->count(),
    );

    expect($entries)->toBeGreaterThanOrEqual(2);
});
