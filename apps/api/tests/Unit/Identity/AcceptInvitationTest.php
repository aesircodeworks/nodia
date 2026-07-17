<?php

use App\Identity\Actions\AcceptInvitation;
use App\Identity\Data\AcceptInvitationData;
use App\Identity\Exceptions\InvitationTokenInvalidException;
use App\Identity\Models\StaffInvitationToken;
use App\Identity\Support\InvitationTokenHasher;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    StaffInvitationToken::query()->delete();
    User::query()->delete();
});

it('lets the first invitation establish credentials and invalidates every sibling invitation', function (): void {
    $user = User::factory()->create(['password_initialized' => false]);
    $first = 'first-invitation-token';
    $sibling = 'sibling-invitation-token';

    foreach ([$first, $sibling] as $token) {
        StaffInvitationToken::factory()->for($user)->create([
            'token_hash' => InvitationTokenHasher::hash($token),
            'expires_at' => Date::now()->addHour(),
        ]);
    }

    app(AcceptInvitation::class)(new AcceptInvitationData($first, 'first-password'));

    expect(Hash::check('first-password', $user->fresh()->password))->toBeTrue()
        ->and(StaffInvitationToken::query()->where('user_id', $user->id)->whereNull('consumed_at')->count())->toBe(0);

    expect(fn () => app(AcceptInvitation::class)(new AcceptInvitationData($sibling, 'second-password')))
        ->toThrow(InvitationTokenInvalidException::class);

    expect(Hash::check('first-password', $user->fresh()->password))->toBeTrue();
});
