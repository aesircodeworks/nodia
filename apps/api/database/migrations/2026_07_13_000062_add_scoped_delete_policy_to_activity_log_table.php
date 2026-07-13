<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stage 3 granted nodia_app and nodia_platform select and insert on
 * activity_log and nothing else (2026_07_09_000018_create_activity_log_
 * table.php, merged, never edited): no role could delete a row at the
 * database privilege level, regardless of what any RLS policy said.
 * Stage 12's retention window (system-design 14.3, config/retention.php's
 * activity_log_days) needs a way to prune rows past that window without
 * reopening the append-only guarantee for everyone else. This migration
 * (stage-12 plan task breakdown item 8) adds a single, narrow path for
 * that:
 *
 * - DELETE is granted to nodia_platform only, never to nodia_app, so a
 *   tenant-scoped request handler (which always runs under nodia_app,
 *   system-design 4.1) still gets a hard permission-denied error on any
 *   delete attempt, exactly as before this migration.
 * - The new activity_log_platform_delete policy restricts even
 *   nodia_platform's grant to rows whose created_at is before
 *   app.activity_log_prune_cutoff, a session setting nobody sets except
 *   the retention pruning command itself (task breakdown item 9).
 *   Ordinary platform-scope request handling
 *   (TenantTransaction::elevateToPlatformRole(), ::asPlatform()) never
 *   sets this setting, so current_setting(..., true) reads NULL, the
 *   comparison is NULL (never true, deny by default, the same posture
 *   applyTenantPolicies() uses for app.tenant_id), and the delete
 *   affects zero rows: the grant exists, but nothing outside the
 *   pruning command's own explicit set_config() call can use it.
 * - No policy applies a tenant_id predicate here (unlike
 *   activity_log_tenant_select/insert): the pruning command scans and
 *   deletes across every tenant in one pass, the same cross-tenant
 *   shape activity_log_platform_read already established for reads.
 * - UPDATE stays ungranted, to both roles, unconditionally: system-
 *   design 14.2 (amended alongside this migration) states rows are
 *   never updated, only archived then deleted past their retention
 *   window, and nothing in this stage needs an update path.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('grant delete on activity_log to nodia_platform');

        DB::statement(<<<'SQL'
            create policy activity_log_platform_delete on activity_log
                for delete to nodia_platform
                using (created_at < nullif(current_setting('app.activity_log_prune_cutoff', true), '')::timestamptz)
            SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists activity_log_platform_delete on activity_log');
        DB::statement('revoke delete on activity_log from nodia_platform');
    }
};
