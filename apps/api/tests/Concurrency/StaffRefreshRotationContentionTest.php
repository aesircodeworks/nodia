<?php

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Laravel\Passport\RefreshToken as PassportRefreshToken;
use Laravel\Passport\Token as PassportToken;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * Slice 2's rotation guard (stage-03 plan): two parallel refresh requests
 * presenting the same refresh token must resolve to exactly one winner,
 * driven by App\Identity\OAuth\IdentityRefreshTokenRepository's
 * conditional UPDATE on revoked, checked by affected-row count, never
 * read-then-write. Driven through the real HTTP kernel in forked workers
 * (Tests\Concurrency\TenantDomainContentionTest's pattern) so each
 * contender runs the full production path, including the database
 * statement that decides the winner.
 */
beforeEach(function (): void {
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    User::query()->delete();
});

/**
 * @param  array<string, mixed>  $payload
 * @return array{status: int, body: array<string, mixed>}
 */
function handleStaffRefreshRequest(array $payload): array
{
    $request = Request::create('/v1/auth/staff/refresh', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], json_encode($payload, JSON_THROW_ON_ERROR));

    $response = app(Kernel::class)->handle($request);

    $body = json_decode((string) $response->getContent(), true);

    return [
        'status' => $response->getStatusCode(),
        'body' => is_array($body) ? $body : [],
    ];
}

it('resolves parallel rotation of one staff refresh token to exactly one winner via the affected-row-count guard', function (): void {
    $user = User::factory()->create(['email' => 'refresh-racer@example.com']);

    /** @var array{refresh_token: string} $login */
    $login = $this->postJson('/v1/auth/staff/token', [
        'email' => 'refresh-racer@example.com',
        'password' => 'password',
    ])->json();

    $accessTokenRow = PassportToken::query()->where('user_id', $user->id)->firstOrFail();
    $originalRefreshRow = PassportRefreshToken::query()->where('access_token_id', $accessTokenRow->id)->firstOrFail();
    $familyId = $originalRefreshRow->family_id;

    $results = ParallelRunner::run(
        2,
        fn (PDO $pdo): array => handleStaffRefreshRequest(['refresh_token' => $login['refresh_token']]),
    );

    $statuses = collect($results)->pluck('status')->sort()->values()->all();

    // Exactly one requester's conditional UPDATE can ever flip revoked
    // false to true for this row, so exactly one 200 and one 401 is
    // guaranteed in every interleaving. league/oauth2-server checks
    // isRefreshTokenRevoked() (a plain read) before the grant reaches the
    // conditional UPDATE guard itself, so the loser's specific code
    // depends on exactly where the two requests interleaved: caught at
    // the guard (invalid_refresh_token, RaceLostHint) if both reach it
    // before either commits, or caught earlier because the winner's
    // commit already landed (refresh_token_reused) if the loser is far
    // enough behind. Either way the core invariant holds: one winner.
    expect($statuses)->toBe([200, 401]);

    $loserBody = collect($results)->firstWhere('status', 401)['body'];
    expect($loserBody['code'] ?? null)->toBeIn(['invalid_refresh_token', 'refresh_token_reused']);

    $familyRows = PassportRefreshToken::query()->where('family_id', $familyId)->get();

    expect($familyRows->firstWhere('id', $originalRefreshRow->id)?->revoked)->toBeTrue();
});
