<?php

use App\Identity\Models\StaffPasswordResetToken;
use App\Identity\Support\PasswordResetTokenHasher;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Laravel\Passport\RefreshToken as PassportRefreshToken;
use Laravel\Passport\Token as PassportToken;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

beforeEach(function (): void {
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    PassportRefreshToken::query()->delete();
    PassportToken::query()->delete();
    StaffPasswordResetToken::query()->delete();
    User::query()->delete();
});

/**
 * @param  array<string, mixed>  $payload
 * @return array{status: int, body: array<string, mixed>}
 */
function handlePasswordResetRaceRequest(string $path, array $payload): array
{
    $request = Request::create($path, 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], json_encode($payload, JSON_THROW_ON_ERROR));

    $response = app(Kernel::class)->handle($request);
    $body = json_decode((string) $response->getContent(), true);

    return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : []];
}

it('leaves no live token when password grant races an all-sessions reset', function (): void {
    $user = User::factory()->create([
        'email' => 'reset-grant-race@example.com',
        'password' => 'the-old-password',
    ]);
    $plainResetToken = 'parallel-reset-token';

    StaffPasswordResetToken::factory()->for($user)->create([
        'token_hash' => PasswordResetTokenHasher::hash($plainResetToken),
        'expires_at' => Date::now()->addHour(),
    ]);

    $results = ParallelRunner::runEach(
        fn (PDO $pdo): array => handlePasswordResetRaceRequest('/v1/auth/staff/token', [
            'email' => $user->email,
            'password' => 'the-old-password',
        ]),
        fn (PDO $pdo): array => handlePasswordResetRaceRequest('/v1/auth/staff/password/reset/confirm', [
            'token' => $plainResetToken,
            'password' => 'the-new-password',
        ]),
    );

    expect($results[1]['status'])->toBe(204)
        ->and(PassportToken::query()->where('user_id', $user->id)->where('revoked', false)->count())->toBe(0)
        ->and(PassportRefreshToken::query()
            ->whereIn('access_token_id', PassportToken::query()->select('id')->where('user_id', $user->id))
            ->where('revoked', false)
            ->count())->toBe(0);
});

it('leaves no live token when refresh grant races an all-sessions reset', function (): void {
    $user = User::factory()->create([
        'email' => 'reset-refresh-race@example.com',
        'password' => 'the-old-password',
    ]);
    $plainResetToken = 'parallel-refresh-reset-token';

    /** @var array{refresh_token: string} $pair */
    $pair = test()->postJson('/v1/auth/staff/token', [
        'email' => $user->email,
        'password' => 'the-old-password',
    ])->assertOk()->json();

    StaffPasswordResetToken::factory()->for($user)->create([
        'token_hash' => PasswordResetTokenHasher::hash($plainResetToken),
        'expires_at' => Date::now()->addHour(),
    ]);

    $results = ParallelRunner::runEach(
        fn (PDO $pdo): array => handlePasswordResetRaceRequest('/v1/auth/staff/refresh', [
            'refresh_token' => $pair['refresh_token'],
        ]),
        fn (PDO $pdo): array => handlePasswordResetRaceRequest('/v1/auth/staff/password/reset/confirm', [
            'token' => $plainResetToken,
            'password' => 'the-new-password',
        ]),
    );

    expect($results[1]['status'])->toBe(204)
        ->and(PassportToken::query()->where('user_id', $user->id)->where('revoked', false)->count())->toBe(0)
        ->and(PassportRefreshToken::query()
            ->whereIn('access_token_id', PassportToken::query()->select('id')->where('user_id', $user->id))
            ->where('revoked', false)
            ->count())->toBe(0);
});
