<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * data_subject_requests: the auditable, idempotent record of an erasure
 * or export request (stage-12 plan, Data model "data_subject_requests").
 * tenant_id is denormalized, matching every other tenant-scoped table's
 * own posture; customer_id is a real FK to customers (the orders table's
 * own posture, not holds' nullable one: a data subject request always
 * names a real customer). type and status are strings backed by
 * App\Identity\Enums\DataSubjectRequestType and DataSubjectRequestStatus,
 * the enums the authoritative list of values (data-conventions); every
 * status transition is a conditional UPDATE checked by affected-row count
 * on App\Identity\Models\DataSubjectRequest, never a bare Eloquent save
 * (data-conventions, stage-12 plan Data model), mirroring
 * App\Reporting\Models\Export's claim/complete/fail posture exactly.
 * requested_by_user_id is a real FK into users (mirroring exports'
 * requested_by_user_id), the staff member acting on the data subject's
 * behalf.
 *
 * The partial unique index on (customer_id, type) where status is
 * pending or processing is the run-once invariant's structural half: a
 * customer has at most one open request per type, backstopping the
 * pending-to-processing claim (custom name per data-conventions' index
 * naming rule, since the builder cannot express a partial index). The
 * (tenant_id, customer_id) index backs the admin list filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_subject_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('customer_id')->constrained();
            $table->string('type');
            $table->string('status')->default('pending');
            $table->foreignUuid('requested_by_user_id')->constrained('users');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->index(['tenant_id', 'customer_id']);
        });

        DB::statement(<<<'SQL'
            create unique index data_subject_requests_open_per_customer_idx
                on data_subject_requests (customer_id, type)
                where status in ('pending', 'processing')
            SQL);

        Rls::applyTenantPolicies('data_subject_requests');
    }

    public function down(): void
    {
        Schema::dropIfExists('data_subject_requests');
    }
};
