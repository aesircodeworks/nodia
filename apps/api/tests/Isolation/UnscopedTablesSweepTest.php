<?php

use App\Support\Database\UnscopedTables;
use Illuminate\Support\Facades\DB;

/**
 * Every public base table must either carry FORCE RLS (tenant-scoped or
 * special-cased like tenants/roles) or sit on the unscoped exclusion list
 * (framework, auth, and queue infrastructure). A table that ships without
 * RLS and without an explicit exclusion does not merge (data-conventions
 * Tenancy; stage-04 plan Queue infrastructure tables).
 */
it('requires every public base table without RLS to be on the unscoped exclusion list', function (): void {
    $tables = collect(DB::select(
        "select c.relname as name, c.relrowsecurity as rls
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public'
              and c.relkind = 'r'
            order by c.relname",
    ));

    expect($tables)->not->toBeEmpty();

    $unscopedWithoutRls = $tables
        ->reject(fn ($table) => (bool) $table->rls)
        ->pluck('name')
        ->sort()
        ->values();

    $excluded = collect(UnscopedTables::names())->sort()->values();

    expect($unscopedWithoutRls->all())->toBe($excluded->all());
});

it('lists failed_jobs and job_batches on the unscoped exclusion list', function (): void {
    expect(UnscopedTables::names())
        ->toContain('failed_jobs')
        ->toContain('job_batches')
        ->toContain('cache')
        ->toContain('oauth_access_tokens');
});
