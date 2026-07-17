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
 *
 * archive_segments (stage-12 plan, Data model "archive_segments"; task
 * breakdown item 9) follows the same failed_jobs precedent: a platform
 * infrastructure manifest table whose rows span every tenant, so RLS
 * does not apply.
 */
final class UnscopedTables
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            'archive_segments',
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
            'staff_invitation_tokens',
            'staff_password_reset_tokens',
            'users',
        ];
    }
}
