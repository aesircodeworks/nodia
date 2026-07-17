<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * activity_log: spatie/laravel-activitylog's published migration adjusted
 * for uuid keys and a non-null tenant_id under RLS (ADR 014,
 * data-conventions, system-design 8.1 and 14.2). Platform-scope entries
 * (cross-tenant platform role use, staff logins outside a tenant context)
 * carry the sentinel platform tenant, never NULL, matching every other
 * tenant-scoped table's convention.
 *
 * Schema follows the currently-installed spatie/laravel-activitylog
 * 5.0.0 published migration (vendor/spatie/laravel-activitylog/database/
 * migrations/create_activity_log_table.php.stub), not the stage-03 plan's
 * original column list, which predates this package's v5 schema change:
 * `batch_uuid` does not exist in this version (the batch system was
 * dropped entirely, see the vendored UPGRADING.md, "Batch system
 * removed") and `attribute_changes` (tracked model changes) is new in
 * v5, replacing what v4 stored inside `properties`. See the stage-03
 * execution journal, task-14, for the full comparison. `laravel-package-
 * tools`'s `HasMigrations::$runsMigrations` defaults to false, so the
 * package never auto-loads its own unmodified stub; only this adjusted
 * copy runs.
 *
 * Append-only by construction: nodia_app and nodia_platform are granted
 * only SELECT and INSERT at the database privilege level, never UPDATE or
 * DELETE, so either fails with a permission-denied error before row-level
 * security is even evaluated. This is a stronger guarantee than an
 * unsatisfiable policy predicate would give (roles's template-mutation
 * denial, by contrast, keeps UPDATE/DELETE granted and lets RLS deny by
 * predicate, because roles genuinely has other rows that ARE mutable
 * under the same grant; activity_log has none, ever).
 *
 * tenant_id is a plain uuid column, deliberately without a foreign key
 * constraint to tenants, unlike every other tenant-scoped table: an audit
 * trail's own reason for existing is to outlive the record it describes
 * (system-design 14.2's compliance framing), and the stage-03 plan's own
 * entity-relationship diagram (system-design 8.1) draws no TENANT-to-
 * ACTIVITY_LOG edge, unlike MEMBERSHIP, CUSTOMER, and ROLE, which all get
 * one. Confirmed this is not merely a diagram omission by trying the
 * constrained() form first: activity_log is genuinely append-only (no
 * UPDATE or DELETE privilege ever, for any role), so once a fixture row
 * referencing a given tenant exists it can never be removed again, which
 * would make that tenant's row permanently undeletable too under a real
 * foreign key, defeating the isolation suite's shared TenantFixture (its
 * two tenant ids are torn down and recreated between every test in every
 * isolation file, including ones with no relation to this table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('event')->nullable();
            $table->string('causer_type')->nullable();
            $table->uuid('causer_id')->nullable();
            $table->jsonb('attribute_changes')->nullable();
            $table->jsonb('properties')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->index(['tenant_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['causer_type', 'causer_id']);
        });

        DB::statement('grant select, insert on activity_log to nodia_app, nodia_platform');
        DB::statement('alter table activity_log enable row level security');
        DB::statement('alter table activity_log force row level security');

        DB::statement(<<<'SQL'
            create policy activity_log_tenant_select on activity_log
                for select
                using (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            SQL);

        DB::statement(<<<'SQL'
            create policy activity_log_tenant_insert on activity_log
                for insert
                with check (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            SQL);

        DB::statement(<<<'SQL'
            create policy activity_log_platform_read on activity_log
                for select to nodia_platform using (true)
            SQL);

        DB::statement(<<<'SQL'
            create policy activity_log_platform_insert on activity_log
                for insert to nodia_platform with check (true)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
