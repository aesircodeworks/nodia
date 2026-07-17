<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * outbox_events: append-only durable domain event log (system-design 9.1,
 * event-conventions envelope, ADR 004). `sequence` is the sole auto-
 * increment exception in the schema (data-conventions): a bigint identity
 * for global replay order, not the primary key. RLS is the plain
 * Rls::applyTenantPolicies posture with no platform write: producers and
 * workers write under nodia_app inside a tenant-scoped transaction; the
 * platform/cross-tenant role only needs SELECT to load envelopes before
 * tenant context is known (stage-04 plan Worker access pattern). Append-
 * only is an application invariant on the OutboxEvent model, not a grant
 * restriction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->bigInteger('sequence')->generatedAs()->always();
            $table->string('type');
            $table->foreignUuid('tenant_id')->constrained();
            $table->string('aggregate_type');
            $table->uuid('aggregate_id');
            $table->string('correlation_id');
            $table->timestampTz('occurred_at');
            $table->jsonb('payload');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique('sequence');
            $table->index(['aggregate_type', 'aggregate_id', 'sequence']);
            $table->index(['type', 'sequence']);
        });

        Rls::applyTenantPolicies('outbox_events');

        // Identity columns still need sequence USAGE for non-owner inserts
        // under the app and platform roles (stage-04 plan task 3 notes).
        DB::statement('grant usage, select on sequence outbox_events_sequence_seq to nodia_app, nodia_platform');
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
