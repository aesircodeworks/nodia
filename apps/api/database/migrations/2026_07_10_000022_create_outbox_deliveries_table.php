<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * outbox_deliveries: per-subscriber progress for each outbox event
 * (system-design 9.2, stage-04 plan Data model). One row per
 * (outbox_event_id, subscriber), created in the same producing transaction
 * as the event so a crash between commit and enqueue leaves a durable
 * pending record for the sweeper. RLS is the plain Rls::applyTenantPolicies
 * posture with no platform write: workers and the sweeper write under
 * nodia_app inside tenant-scoped transactions; the platform/cross-tenant
 * role only needs SELECT for stranded-delivery scans (stage-04 plan
 * Worker access pattern). The pending-to-processed transition is a
 * conditional UPDATE on the model, not a grant restriction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('outbox_event_id')->constrained('outbox_events');
            $table->foreignUuid('tenant_id')->constrained();
            $table->string('subscriber');
            $table->string('status');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('last_enqueued_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['outbox_event_id', 'subscriber']);
        });

        // Partial index for the sweeper scan of pending deliveries; the
        // schema builder cannot express partial indexes (data-conventions
        // custom-name format {table}_{purpose}_idx).
        DB::statement(<<<'SQL'
            create index outbox_deliveries_pending_sweep_idx
                on outbox_deliveries (subscriber, created_at)
                where status = 'pending'
            SQL);

        Rls::applyTenantPolicies('outbox_deliveries');
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_deliveries');
    }
};
