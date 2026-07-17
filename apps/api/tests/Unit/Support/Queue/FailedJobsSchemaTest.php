<?php

use App\Support\Database\UnscopedTables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

it('creates failed_jobs with a uuid primary key and no auto-increment', function (): void {
    expect(Schema::hasTable('failed_jobs'))->toBeTrue();

    $columns = collect(DB::select(
        "select column_name, data_type, is_nullable
            from information_schema.columns
            where table_schema = 'public' and table_name = 'failed_jobs'",
    ))->keyBy('column_name');

    expect($columns->keys()->sort()->values()->all())->toBe([
        'connection',
        'exception',
        'failed_at',
        'payload',
        'queue',
        'uuid',
    ]);

    expect($columns['uuid']->data_type)->toBe('uuid')
        ->and($columns['uuid']->is_nullable)->toBe('NO')
        ->and($columns['connection']->data_type)->toBe('character varying')
        ->and($columns['queue']->data_type)->toBe('character varying')
        ->and($columns['payload']->data_type)->toBe('text')
        ->and($columns['exception']->data_type)->toBe('text')
        ->and($columns['failed_at']->data_type)->toBe('timestamp with time zone');

    $pk = DB::selectOne(
        "select a.attname as column_name
            from pg_index i
            join pg_attribute a on a.attrelid = i.indrelid and a.attnum = any (i.indkey)
            where i.indrelid = 'public.failed_jobs'::regclass
              and i.indisprimary",
    );

    expect($pk)->not->toBeNull()
        ->and($pk->column_name)->toBe('uuid');

    $identity = DB::selectOne(
        "select count(*)::int as n
            from information_schema.columns
            where table_schema = 'public'
              and table_name = 'failed_jobs'
              and is_identity = 'YES'",
    );

    expect($identity->n)->toBe(0);

    $serialDefault = DB::selectOne(
        "select column_default
            from information_schema.columns
            where table_schema = 'public'
              and table_name = 'failed_jobs'
              and column_name = 'uuid'",
    );

    expect($serialDefault->column_default)->toBeNull();
});

it('creates job_batches with a string primary key and no auto-increment', function (): void {
    expect(Schema::hasTable('job_batches'))->toBeTrue();

    $pk = DB::selectOne(
        "select a.attname as column_name, format_type(a.atttypid, a.atttypmod) as data_type
            from pg_index i
            join pg_attribute a on a.attrelid = i.indrelid and a.attnum = any (i.indkey)
            where i.indrelid = 'public.job_batches'::regclass
              and i.indisprimary",
    );

    expect($pk)->not->toBeNull()
        ->and($pk->column_name)->toBe('id')
        ->and($pk->data_type)->toContain('character varying');

    $identity = DB::selectOne(
        "select count(*)::int as n
            from information_schema.columns
            where table_schema = 'public'
              and table_name = 'job_batches'
              and is_identity = 'YES'",
    );

    expect($identity->n)->toBe(0);
});

it('ships failed_jobs and job_batches as unscoped platform infrastructure', function (string $table): void {
    $tenantId = DB::selectOne(
        "select column_name from information_schema.columns
            where table_schema = 'public' and table_name = ? and column_name = 'tenant_id'",
        [$table],
    );

    expect($tenantId)->toBeNull();

    $relation = DB::selectOne(
        "select relrowsecurity, relforcerowsecurity
            from pg_class
            where relname = ? and relnamespace = 'public'::regnamespace",
        [$table],
    );

    expect($relation)->not->toBeNull()
        ->and((bool) $relation->relrowsecurity)->toBeFalse()
        ->and((bool) $relation->relforcerowsecurity)->toBeFalse();

    foreach (['nodia_app', 'nodia_platform'] as $role) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            $granted = DB::selectOne(
                'select has_table_privilege(?, ?, ?) as granted',
                [$role, $table, $privilege],
            );

            expect((bool) $granted->granted)->toBeTrue("expected {$role} to hold {$privilege} on {$table}");
        }
    }

    expect(UnscopedTables::names())->toContain($table);
})->with([
    'failed_jobs',
    'job_batches',
]);
