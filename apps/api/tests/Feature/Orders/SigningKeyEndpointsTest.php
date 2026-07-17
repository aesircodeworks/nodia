<?php

use App\CheckIn\Models\CheckInAssignment;
use App\EventCatalog\Models\Event;
use App\Identity\Capability;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Orders\Enums\SigningKeyStatus;
use App\Orders\Models\EventSigningKey;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;
use Tests\Support\TenantStaff;

/*
 * Stage-09 plan, Endpoints "GET/POST /v1/events/{event}/signing-keys"
 * and Slice 2: GET returns active and retired keys with secrets and
 * excludes revoked; POST rotates the event's key, is activity-logged,
 * and returns 201; both authorize through checkin.scan plus assignment
 * (or checkin.manage) for GET, and checkin.manage alone for POST.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->eventId = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId])->id,
    );
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('check_in_assignments')->where('tenant_id', $this->tenantId)->delete();
        DB::table('event_signing_keys')->where('tenant_id', $this->tenantId)->delete();
        DB::table('memberships')->where('tenant_id', $this->tenantId)->delete();
        DB::table('roles')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );

    User::query()->delete();
});

it('rotates the event signing key on POST and returns 201', function (): void {
    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CheckinManage),
        'X-Tenant-Id' => $this->tenantId,
    ];

    // A fresh event has no active key yet: the first rotation seeds
    // version 1 (retired immediately by this same call, per RotateSigningKey's
    // own contract) and activates version 2.
    $first = $this->postJson('/v1/events/'.$this->eventId.'/signing-keys', [], $headers);
    $first->assertStatus(201)->assertConformsToOpenApi();
    $first->assertJsonPath('key_version', 2)
        ->assertJsonPath('status', 'active');
    expect($first->json('secret'))->not->toBeEmpty();

    $second = $this->postJson('/v1/events/'.$this->eventId.'/signing-keys', [], $headers);
    $second->assertStatus(201)->assertConformsToOpenApi();
    $second->assertJsonPath('key_version', 3)
        ->assertJsonPath('status', 'active');

    $outgoing = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSigningKey::query()->where('id', $first->json('id'))->firstOrFail(),
    );

    expect($outgoing->status)->toBe(SigningKeyStatus::Retired);
});

it('marks the outgoing key revoked when revoke_previous is true', function (): void {
    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CheckinManage),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $first = $this->postJson('/v1/events/'.$this->eventId.'/signing-keys', [], $headers);
    $first->assertStatus(201);

    $second = $this->postJson('/v1/events/'.$this->eventId.'/signing-keys', ['revoke_previous' => true], $headers);
    $second->assertStatus(201)->assertConformsToOpenApi();

    $outgoing = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSigningKey::query()->where('id', $first->json('id'))->firstOrFail(),
    );

    expect($outgoing->status)->toBe(SigningKeyStatus::Revoked);
});

it('returns active and retired keys with secrets on GET, excluding revoked', function (): void {
    $manageHeaders = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CheckinManage),
        'X-Tenant-Id' => $this->tenantId,
    ];

    // seeds version 1 (retired by this same call), activates version 2
    $this->postJson('/v1/events/'.$this->eventId.'/signing-keys', [], $manageHeaders)->assertStatus(201);
    // retires version 2, activates version 3
    $this->postJson('/v1/events/'.$this->eventId.'/signing-keys', [], $manageHeaders)->assertStatus(201);
    // revokes version 3, activates version 4
    $this->postJson('/v1/events/'.$this->eventId.'/signing-keys', ['revoke_previous' => true], $manageHeaders)->assertStatus(201);

    $response = $this->getJson('/v1/events/'.$this->eventId.'/signing-keys', $manageHeaders);
    $response->assertStatus(200)->assertConformsToOpenApi();

    $data = $response->json('data');
    expect($data)->toHaveCount(3);

    $statuses = array_column($data, 'status');
    sort($statuses);
    expect($statuses)->toBe(['active', 'retired', 'retired']);

    foreach ($data as $key) {
        expect($key['secret'])->not->toBeEmpty();
    }
});

it('rejects GET from checkin.scan with no assignment for the event', function (): void {
    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CheckinScan),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $response = $this->getJson('/v1/events/'.$this->eventId.'/signing-keys', $headers);
    $response->assertStatus(403);
    $response->assertJsonPath('code', 'checkin_not_assigned');
});

it('allows GET from checkin.scan with an assignment for the event', function (): void {
    $user = User::factory()->create();
    $token = StaffTokens::issue($user);
    $user->forceFill(['mfa_enabled' => true, 'mfa_confirmed_at' => now()])->save();

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($user): void {
        $role = Role::factory()->create([
            'tenant_id' => $this->tenantId,
            'capabilities' => [Capability::CheckinScan->value],
        ]);
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role_id' => $role->id,
            'scope' => MembershipScope::Tenant,
        ]);
        CheckInAssignment::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
            'user_id' => $user->id,
        ]);
    });

    $headers = [
        'Authorization' => 'Bearer '.$token,
        'X-Tenant-Id' => $this->tenantId,
    ];

    $response = $this->getJson('/v1/events/'.$this->eventId.'/signing-keys', $headers);
    $response->assertStatus(200)->assertConformsToOpenApi();
});

it('rejects a checkin.scan caller from POST even when assigned', function (): void {
    $user = User::factory()->create();
    $token = StaffTokens::issue($user);
    $user->forceFill(['mfa_enabled' => true, 'mfa_confirmed_at' => now()])->save();

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($user): void {
        $role = Role::factory()->create([
            'tenant_id' => $this->tenantId,
            'capabilities' => [Capability::CheckinScan->value],
        ]);
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role_id' => $role->id,
            'scope' => MembershipScope::Tenant,
        ]);
        CheckInAssignment::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
            'user_id' => $user->id,
        ]);
    });

    $headers = [
        'Authorization' => 'Bearer '.$token,
        'X-Tenant-Id' => $this->tenantId,
    ];

    $response = $this->postJson('/v1/events/'.$this->eventId.'/signing-keys', [], $headers);
    $response->assertStatus(403);
    $response->assertJsonPath('code', 'missing_capability');
});

it('rejects GET and POST with no checkin capability at all', function (): void {
    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::OrdersView),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $this->getJson('/v1/events/'.$this->eventId.'/signing-keys', $headers)->assertStatus(403);
    $this->postJson('/v1/events/'.$this->eventId.'/signing-keys', [], $headers)->assertStatus(403);
});

it('renders event_not_found for an unknown event id on GET and POST', function (): void {
    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CheckinManage),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $unknownEventId = Str::uuid7()->toString();

    $get = $this->getJson('/v1/events/'.$unknownEventId.'/signing-keys', $headers);
    $get->assertStatus(404);
    $get->assertJsonPath('code', 'event_not_found');

    $post = $this->postJson('/v1/events/'.$unknownEventId.'/signing-keys', [], $headers);
    $post->assertStatus(404);
    $post->assertJsonPath('code', 'event_not_found');
});

it('records an activity log entry for the rotation', function (): void {
    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CheckinManage),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $this->postJson('/v1/events/'.$this->eventId.'/signing-keys', [], $headers)->assertStatus(201);

    $entries = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('activity_log')->where('tenant_id', $this->tenantId)->count(),
    );

    expect($entries)->toBeGreaterThan(0);
});
