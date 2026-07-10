<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Queue dead-letter and batching tables for Horizon (stage-04 plan Queue
 * infrastructure tables). Laravel's published stubs use a bigint
 * auto-increment primary key on failed_jobs; data-conventions forbids
 * auto-increment columns, so the job uuid is the primary key instead.
 * job_batches already uses a string primary key in the framework stub.
 *
 * Platform infrastructure, not tenant-scoped: no tenant_id, no RLS.
 * Rls::grantUnscoped lets workers write failed jobs from inside
 * tenant-scoped nodia_app transactions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestampTz('failed_at')->useCurrent();

            $table->index(['connection', 'queue', 'failed_at']);
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Rls::grantUnscoped('failed_jobs');
        Rls::grantUnscoped('job_batches');
    }

    public function down(): void
    {
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
