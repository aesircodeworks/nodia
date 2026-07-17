<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * event_seats: one row per template seat materialized onto a seated
 * event on publish (stage-06 plan, Data model "event_seats"; system-
 * design 6.2). tenant_id is denormalized, matching every other tenant-
 * scoped table's own posture. seat_id is a real, restricting FK to
 * App\EventCatalog's seats table (Laravel's default for constrained(),
 * no cascadeOnDelete() call): this is the Stage 5b deferral, closing the
 * gap App\EventCatalog\Actions\DeleteSeatMap's own docblock already
 * anticipated ("Stage 6 extends the same code to templates referenced by
 * materialized event_seats") by making a delete of a materialized
 * template's map fail at the database with the
 * event_seats_seat_id_foreign violation, which DeleteSeatMap now also
 * catches and maps to catalog.seat_map_in_use. hold_id is a real,
 * unconstrained-on-delete FK to holds (App\Inventory's own table, no
 * cross-context model reach needed) and stays null except while a seat
 * is held. ticket_type_id is nullable: a blocked or unzoned seat has no
 * type, and every seat materializes unzoned (stage-06 plan, event_seats
 * section: "Nothing upstream carries zoning before publish").
 *
 * unique(event_id, seat_id) is the structural guarantee that makes
 * double-booking (and re-publish duplication) impossible; the
 * (event_id, status), hold_id, and (event_id, ticket_type_id) indexes
 * back map rendering and counts, release/expiry lookups, and zone
 * counts respectively (stage-06 plan, event_seats section).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_seats', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('event_id')->constrained();
            $table->foreignUuid('seat_id')->constrained();
            $table->foreignUuid('ticket_type_id')->nullable()->constrained();
            $table->string('status');
            $table->foreignUuid('hold_id')->nullable()->constrained();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['event_id', 'seat_id']);
            $table->index(['event_id', 'status']);
            $table->index('hold_id');
            $table->index(['event_id', 'ticket_type_id']);
        });

        Rls::applyTenantPolicies('event_seats');
    }

    public function down(): void
    {
        Schema::dropIfExists('event_seats');
    }
};
