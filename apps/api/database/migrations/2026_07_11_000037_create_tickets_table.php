<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * tickets: issued exactly once on the transition to paid (system-design
 * 7.1, 8.3; stage-07 plan Data model "tickets"). event_id is
 * denormalized beyond the ERD, mirroring the section 4.2 rationale for
 * tenant_id: Stage 9's manifest and the QR signing key lookup are
 * single-table. event_seat_id is a plain uuid Inventory reference with
 * no FK, like orders.hold_id. No barcode or token column exists: the QR
 * payload is computed on render over ticket id, event id, and
 * qr_rotation_counter (system-design 8.3 notes), and bumping the
 * counter invalidates every previously rendered payload. The partial
 * unique index scopes seat uniqueness to live tickets so a canceled or
 * refunded ticket leaves the seat free for a Stage 8b reissue; merged
 * migrations are never edited, so the scoping ships now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('order_id')->constrained();
            $table->foreignUuid('ticket_type_id')->constrained();
            $table->foreignUuid('event_id')->constrained();
            $table->uuid('event_seat_id')->nullable();
            $table->string('status')->default('issued');
            $table->string('attendee_name')->nullable();
            $table->timestampTz('issued_at');
            $table->integer('qr_rotation_counter')->default(0);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->index(['tenant_id', 'event_id']);
        });

        DB::statement("create unique index tickets_live_seat_unique on tickets (event_seat_id) where status = 'issued' and event_seat_id is not null");

        Rls::applyTenantPolicies('tickets');
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
