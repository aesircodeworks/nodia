<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * archive_segments: the manifest table the archive-then-prune commands
 * write once an uploaded object storage segment's checksum verifies,
 * immediately before they delete the source rows the segment holds
 * (stage-12 plan, Data model "archive_segments"; task breakdown item 9;
 * system-design 9.1 and 14.2/14.3).
 *
 * Segments span tenants (they are batches by global order for
 * outbox_events, task breakdown item 10, or by created_at for
 * activity_log, this task), so per-tenant RLS does not apply: no
 * tenant_id, no policy. This follows the Stage 4 failed_jobs precedent
 * exactly (2026_07_10_000021_create_failed_jobs_and_job_batches_tables.
 * php): a platform infrastructure table on the isolation-sweep exclusion
 * list (App\Support\Database\UnscopedTables, tests/Isolation/
 * UnscopedTablesSweepTest) instead of RLS, with Rls::grantUnscoped
 * granting full CRUD to nodia_app and nodia_platform so the archiving
 * commands can write the manifest row from inside the per-tenant
 * transaction their own deletes run under (Slice 4's own deletion
 * mechanics) as well as the cross-tenant platform transaction this
 * task's own activity log archiver runs entirely under.
 *
 * The unique object_key stops a retried archiver run from writing two
 * manifest rows for the same uploaded object; the (source, range_from)
 * index lets archive-aware replay (task breakdown item 10) locate a
 * source's segments in order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_segments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source');
            $table->string('range_from');
            $table->string('range_to');
            $table->string('object_key')->unique();
            $table->integer('row_count');
            $table->string('checksum');
            $table->timestampTz('archived_at');
            $table->timestampsTz();

            $table->index(['source', 'range_from']);
        });

        Rls::grantUnscoped('archive_segments');
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_segments');
    }
};
