<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * seat_maps and seats: reusable venue seating templates (stage-05b plan,
 * Data model, task breakdown item 1, TDD slice 1). Both tables ship
 * together in one migration because seats' FK and unique index depend on
 * seat_maps existing first, and the two are one document at the API
 * layer (a later task's UpsertSeatMap Action).
 *
 * seat_maps.tenant_id is denormalized (system-design 4.2) rather than
 * resolved through venue_id, matching every other tenant-scoped table's
 * own posture; venue_id is on delete restrict (Laravel's default for
 * constrained(), no cascadeOnDelete() call), mirroring the stage-05b plan
 * Data model's "template deletion is explicit". Unique (venue_id, name)
 * keeps a venue's templates unambiguous to staff.
 *
 * seats.tenant_id is likewise denormalized; seat_map_id cascades on
 * delete (a template owns its seats, stage-05b plan Deletion semantics).
 * `row` is a PostgreSQL reserved word; Laravel's query grammar quotes
 * every identifier it emits, so the schema and Eloquent paths here are
 * safe, but any future hand-written raw SQL touching this column must
 * quote it explicitly. The unique (seat_map_id, section, row, number)
 * index is the natural key of a seat within a template and the database
 * guarantee a later task's upsert natural-key matching relies on: no
 * concurrent write can produce duplicate seats.
 *
 * Both tables take the standard Rls::applyTenantPolicies posture with no
 * platform write policy, mirroring venues, events, and ticket_types:
 * mutation is tenant admin surface (seat_maps.manage gated, a later task
 * in this stage), not platform-admin surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_maps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('venue_id')->constrained();
            $table->string('name');
            $table->jsonb('layout');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['venue_id', 'name']);
            $table->index(['tenant_id', 'venue_id']);
        });

        Rls::applyTenantPolicies('seat_maps');

        Schema::create('seats', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('seat_map_id')->constrained()->cascadeOnDelete();
            $table->string('section');
            $table->string('row');
            $table->string('number');
            $table->integer('position_x')->nullable();
            $table->integer('position_y')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['seat_map_id', 'section', 'row', 'number']);
            $table->index('tenant_id');
        });

        Rls::applyTenantPolicies('seats');
    }

    public function down(): void
    {
        Schema::dropIfExists('seats');
        Schema::dropIfExists('seat_maps');
    }
};
