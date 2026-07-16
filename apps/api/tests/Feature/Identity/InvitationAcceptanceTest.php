<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Mail\StaffInvitationMail;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;

/*
 * Stage-03 plan, task breakdown item 9: POST /v1/auth/staff/invitation/
 * accept. Every case invites a real user through the real POST
 * /v1/memberships endpoint first (mirroring MembershipEndpointsTest's own
 * "issue a bearer through a real exchange" precedent) and recovers the
 * mailed acceptance token from Mail::fake()'s captured StaffInvitationMail
 * instead of reaching into App\Identity\Support\InvitationToken directly,
 * so these tests prove the endpoint end to end.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->withHeaders([
        'Authorization' => 'Bearer '.invitationEndpointBearer($this->tenantId),
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('memberships')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

function invitationEndpointBearer(string $tenantId): string
{
    $user = User::factory()->create();
    $token = StaffTokens::issue($user);

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($user, $tenantId): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'role_id' => Role::factory()->create(['tenant_id' => $tenantId, 'capabilities' => ['memberships.manage']])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    return $token;
}

/**
 * Invites a fresh, uniquely-emailed user and returns the acceptance token
 * mailed to them, without asserting anything about the mail itself
 * (MembershipEndpointsTest already covers that shape).
 *
 * @return array{email: string, token: string}
 */
function inviteAndCaptureToken(string $tenantId, ?string $email = null): array
{
    Mail::fake();

    $email ??= 'invitee-'.Str::uuid7().'@example.com';

    $roleId = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Role::factory()->create(['tenant_id' => $tenantId])->id,
    );

    $response = test()->postJson('/v1/memberships', [
        'email' => $email,
        'name' => 'Invitation Test Invitee',
        'role_id' => $roleId,
    ])->assertCreated();

    $mail = Mail::sent(StaffInvitationMail::class)->sole();

    return ['email' => $email, 'token' => $mail->token];
}

it('accepts a valid token, sets the password, and allows login afterwards', function () {
    ['email' => $email, 'token' => $token] = inviteAndCaptureToken($this->tenantId);

    $this->withoutToken()
        ->postJson('/v1/auth/staff/invitation/accept', ['token' => $token, 'password' => 'a-real-password'])
        ->assertNoContent()
        ->assertConformsToOpenApi();

    $this->withoutToken()
        ->postJson('/v1/auth/staff/token', ['email' => $email, 'password' => 'a-real-password'])
        ->assertOk()
        ->assertJsonStructure(['access_token']);
});

it('rejects replaying an already-accepted token with invitation_token_invalid', function () {
    ['token' => $token] = inviteAndCaptureToken($this->tenantId);

    $this->withoutToken()
        ->postJson('/v1/auth/staff/invitation/accept', ['token' => $token, 'password' => 'a-real-password'])
        ->assertNoContent();

    // The same token, redeemed a second time (an attacker replaying a
    // captured invitation email after the legitimate recipient accepted),
    // must not reset the password again: the row is consumed, so the guard
    // renders it identically to an unknown token.
    $this->withoutToken()
        ->postJson('/v1/auth/staff/invitation/accept', ['token' => $token, 'password' => 'an-attacker-password'])
        ->assertUnauthorized()
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'invitation_token_invalid');
});

it('rejects an expired token with invitation_token_expired under the fake clock', function () {
    $this->freezeTime();

    ['token' => $token] = inviteAndCaptureToken($this->tenantId);

    $this->travel(config()->integer('identity.invitation_token_ttl_minutes') + 1)->minutes();

    $this->withoutToken()
        ->postJson('/v1/auth/staff/invitation/accept', ['token' => $token, 'password' => 'a-real-password'])
        ->assertUnauthorized()
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'invitation_token_expired');
});

it('accepts a token one second before expiry and rejects one issued identically at the expiry instant', function () {
    $this->freezeTime();

    $ttlSeconds = config()->integer('identity.invitation_token_ttl_minutes') * 60;

    ['email' => $email, 'token' => $token] = inviteAndCaptureToken($this->tenantId);

    $this->travel($ttlSeconds - 1)->seconds();

    $this->withoutToken()
        ->postJson('/v1/auth/staff/invitation/accept', ['token' => $token, 'password' => 'a-real-password'])
        ->assertNoContent();

    $this->travelBack();
    $this->freezeTime();

    ['token' => $secondToken] = inviteAndCaptureToken($this->tenantId, $email.'.two');

    // The exact expiry instant counts as expired (">=", not ">"), matching
    // tests/Unit/Time/FrameworkClockTest.php's own precedent for every
    // other TTL in this codebase.
    $this->travel($ttlSeconds)->seconds();

    $this->withoutToken()
        ->postJson('/v1/auth/staff/invitation/accept', ['token' => $secondToken, 'password' => 'a-real-password'])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'invitation_token_expired');
});

it('rejects a tampered token with invitation_token_invalid', function () {
    ['token' => $token] = inviteAndCaptureToken($this->tenantId);

    $tampered = substr($token, 0, -1).($token[-1] === 'a' ? 'b' : 'a');

    $this->withoutToken()
        ->postJson('/v1/auth/staff/invitation/accept', ['token' => $tampered, 'password' => 'a-real-password'])
        ->assertUnauthorized()
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'invitation_token_invalid');
});

it('rejects a malformed token with invitation_token_invalid', function () {
    $this->withoutToken()
        ->postJson('/v1/auth/staff/invitation/accept', ['token' => 'not-a-real-token', 'password' => 'a-real-password'])
        ->assertUnauthorized()
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'invitation_token_invalid');
});

it('rejects an invalid payload with request.validation_failed', function () {
    $this->withoutToken()
        ->postJson('/v1/auth/staff/invitation/accept', ['token' => '', 'password' => 'short'])
        ->assertUnprocessable()
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'request.validation_failed');
});
