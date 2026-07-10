<?php

namespace App\Support\Database;

/**
 * Public base tables that intentionally lack tenant_id and RLS. These are
 * framework, authentication, and queue infrastructure rather than
 * tenant-scoped domain data. The isolation suite's unscoped-table sweep
 * (tests/Isolation/UnscopedTablesSweepTest) requires every public table
 * without FORCE RLS to appear here; adding a table without RLS and without
 * an exclusion fails the suite (data-conventions Tenancy; stage-04 plan
 * Queue infrastructure tables).
 */
final class UnscopedTables
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            'cache',
            'cache_locks',
            'failed_jobs',
            'job_batches',
            'mfa_recovery_codes',
            'migrations',
            'oauth_access_tokens',
            'oauth_auth_codes',
            'oauth_clients',
            'oauth_device_codes',
            'oauth_refresh_tokens',
            'password_reset_tokens',
            'sessions',
            'staff_password_reset_tokens',
            'users',
        ];
    }
}
