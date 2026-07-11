<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * holds: a buyer's temporary claim on inventory (system-design 6.1,
 * stage-06 plan Data model "holds"). tenant_id is denormalized, matching
 * every other tenant-scoped table's own posture. event_id and
 * customer_id are real FKs to events and customers respectively, the
 * same cross-context FK posture App\EventCatalog's event_seats.seat_id
 * FK to seats will use later in this stage; customer_id is nullable by
 * design (anonymous, guest-started holds, Risks: "Nullable customer_id
 * on holds"). status is a string backed by App\Inventory\Enums\
 * HoldStatus, the enum the authoritative list of states
 * (data-conventions). Every status transition is a conditional UPDATE
 * checked by affected-row count in App\Inventory\Actions, never a bare
 * Eloquent save (stage-06 plan Data model "holds", master plan test-first
 * rule 2).
 *
 * The (tenant_id, status, expires_at) index backs the future sweeper's
 * scan (App\Inventory\Actions\ReleaseExpiredHolds, a later task); event_id
 * and customer_id each get their own index (customer_id ahead of need,
 * for Stage 10's per-customer limits, cheap to ship now per the plan's
 * own note).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('event_id')->constrained();
            $table->foreignUuid('customer_id')->nullable()->constrained();
            $table->string('status');
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->index(['tenant_id', 'status', 'expires_at']);
        });

        Rls::applyTenantPolicies('holds');
    }

    public function down(): void
    {
        Schema::dropIfExists('holds');
    }
};
