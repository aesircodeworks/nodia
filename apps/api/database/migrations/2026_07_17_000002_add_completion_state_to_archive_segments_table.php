<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archive_segments', function (Blueprint $table): void {
            $table->timestampTz('completed_at')->nullable();
            $table->unique(
                ['source', 'range_from', 'range_to'],
                'archive_segments_source_range_unique',
            );
        });

        // Activity-log archival deletes in the same transaction as its
        // manifest insert, so every pre-existing activity manifest is
        // already complete. Legacy outbox manifests stay null and are
        // recovered from their checksummed objects on the next run.
        DB::table('archive_segments')
            ->where('source', 'activity_log')
            ->update(['completed_at' => DB::raw('archived_at')]);
    }

    public function down(): void
    {
        Schema::table('archive_segments', function (Blueprint $table): void {
            $table->dropUnique('archive_segments_source_range_unique');
            $table->dropColumn('completed_at');
        });
    }
};
