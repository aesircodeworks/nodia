<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use Illuminate\Support\Facades\DB;

/**
 * Throwaway probe tables for the isolation harness, created per test and
 * never via migrations. createWithPolicy() is the reference implementation
 * of the RLS pattern every tenant-scoped migration must follow
 * (data-conventions Tenancy; ADR 003). FORCE matters because the test
 * connection owns the table and PostgreSQL exempts owners from RLS
 * otherwise.
 */
final class ProbeTable
{
    public static function createWithPolicy(string $table): void
    {
        self::createWithoutPolicy($table);

        DB::statement("alter table {$table} enable row level security");
        DB::statement("alter table {$table} force row level security");
        DB::statement(<<<SQL
            create policy tenant_isolation on {$table}
                using (tenant_id = current_setting('app.tenant_id')::uuid)
                with check (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public static function createWithoutPolicy(string $table): void
    {
        DB::statement(<<<SQL
            create table {$table} (
                id uuid primary key,
                tenant_id uuid not null,
                label text,
                created_at timestamptz,
                updated_at timestamptz
            )
            SQL);
    }

    public static function drop(string $table): void
    {
        DB::statement("drop table if exists {$table}");
    }
}
