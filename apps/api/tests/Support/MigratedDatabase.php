<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;

/**
 * Runs the real migrations once per test process so suites that exercise
 * migrated tables (tenants and every tenant-scoped table after it) prove
 * the actual migration files, RLS policies included, rather than a schema
 * copy. Must run before RlsHonestConnection downgrades the connection so
 * migrations execute as the configured provisioning user, mirroring
 * production, and so the downgraded role exercises the per-table GRANTs
 * the migrations themselves issue.
 */
final class MigratedDatabase
{
    private static bool $migrated = false;

    public static function ensure(): void
    {
        if (self::$migrated) {
            return;
        }

        Artisan::call('migrate:fresh');

        self::$migrated = true;
    }
}
