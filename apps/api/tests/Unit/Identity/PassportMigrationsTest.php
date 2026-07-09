<?php

use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

it('types user_id as uuid on every oauth table that carries one', function (string $table): void {
    $column = DB::selectOne(
        'select data_type from information_schema.columns where table_name = ? and column_name = ?',
        [$table, 'user_id'],
    );

    expect($column)->not->toBeNull()
        ->and($column->data_type)->toBe('uuid');
})->with([
    'oauth_auth_codes',
    'oauth_access_tokens',
    'oauth_device_codes',
]);

it('ships the Passport oauth tables with no tenant_id column, the sanctioned infrastructure exception', function (string $table): void {
    $column = DB::selectOne(
        'select column_name from information_schema.columns where table_name = ? and column_name = ?',
        [$table, 'tenant_id'],
    );

    expect($column)->toBeNull();
})->with([
    'oauth_auth_codes',
    'oauth_access_tokens',
    'oauth_refresh_tokens',
    'oauth_clients',
    'oauth_device_codes',
]);

it('leaves row level security disabled on the Passport oauth tables', function (string $table): void {
    $relation = DB::selectOne(
        "select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = 'public'::regnamespace",
        [$table],
    );

    expect($relation)->not->toBeNull()
        ->and((bool) $relation->relrowsecurity)->toBeFalse()
        ->and((bool) $relation->relforcerowsecurity)->toBeFalse();
})->with([
    'oauth_auth_codes',
    'oauth_access_tokens',
    'oauth_refresh_tokens',
    'oauth_clients',
    'oauth_device_codes',
]);

it('grants nodia_app and nodia_platform full access to every oauth table', function (string $table): void {
    foreach (['nodia_app', 'nodia_platform'] as $role) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            $granted = DB::selectOne('select has_table_privilege(?, ?, ?) as granted', [$role, $table, $privilege]);

            expect((bool) $granted->granted)->toBeTrue("expected {$role} to hold {$privilege} on {$table}");
        }
    }
})->with([
    'oauth_auth_codes',
    'oauth_access_tokens',
    'oauth_refresh_tokens',
    'oauth_clients',
    'oauth_device_codes',
]);

it('seeds a public password-grant client bound to the staff (users) provider', function (): void {
    $client = Client::query()->where('provider', 'users')->firstOrFail();

    expect($client->hasGrantType('password'))->toBeTrue()
        ->and($client->hasGrantType('refresh_token'))->toBeTrue()
        ->and($client->secret)->toBeNull()
        ->and($client->revoked)->toBeFalse();
});

it('seeds a public password-grant client bound to the customer (customers) provider', function (): void {
    $client = Client::query()->where('provider', 'customers')->firstOrFail();

    expect($client->hasGrantType('password'))->toBeTrue()
        ->and($client->hasGrantType('refresh_token'))->toBeTrue()
        ->and($client->secret)->toBeNull()
        ->and($client->revoked)->toBeFalse();
});
