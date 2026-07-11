<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-05c plan, task breakdown item 6, Data model 'event_search_documents':
 * proves the migration's schema-level shape directly against
 * information_schema/pg_indexes rather than only through the isolation
 * suite's row-level probes, mirroring
 * tests/Unit/Tenancy/TenantSettlementCurrencySchemaTest.php's own
 * precedent. search_vector must be a real tsvector column that is not a
 * GENERATED ALWAYS expression (to_tsvector(regconfig, text) with a
 * column-derived regconfig is not immutable, so Postgres cannot express
 * it as a generated column), and the GIN index must exist under its
 * data-conventions-mandated custom name.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

it('adds a non-generated tsvector search_vector column', function (): void {
    $column = DB::selectOne(
        "select data_type, is_nullable, is_generated
            from information_schema.columns
            where table_name = 'event_search_documents' and column_name = 'search_vector'",
    );

    expect($column)->not->toBeNull()
        ->and($column->data_type)->toBe('tsvector')
        ->and($column->is_nullable)->toBe('NO')
        ->and($column->is_generated)->toBe('NEVER');
});

it('creates a GIN index on search_vector under the custom name', function (): void {
    $index = DB::selectOne(
        "select indexdef
            from pg_indexes
            where tablename = 'event_search_documents' and indexname = 'event_search_documents_search_vector_idx'",
    );

    expect($index)->not->toBeNull()
        ->and($index->indexdef)->toContain('USING gin (search_vector)');
});

it('enforces a unique constraint on (event_id, locale)', function (): void {
    $constraint = DB::selectOne(
        "select conname
            from pg_constraint
            where conrelid = 'event_search_documents'::regclass and contype = 'u'",
    );

    expect($constraint)->not->toBeNull()
        ->and($constraint->conname)->toBe('event_search_documents_event_id_locale_unique');
});

it('cascades deletes from events through event_id', function (): void {
    $foreignKey = DB::selectOne(
        "select confdeltype
            from pg_constraint
            where conrelid = 'event_search_documents'::regclass and conname = 'event_search_documents_event_id_foreign'",
    );

    expect($foreignKey)->not->toBeNull()
        ->and($foreignKey->confdeltype)->toBe('c');
});
