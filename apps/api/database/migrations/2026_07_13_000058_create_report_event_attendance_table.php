<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * report_event_attendance: one row per tenant, event, and ticket type
 * (stage-11 plan, Data model "report_event_attendance"). A
 * pre-aggregated read model, not a source-of-truth table, so it carries
 * the report_ prefix and its owning model sets $table explicitly,
 * mirroring report_daily_sales and report_event_finance. event_id and
 * ticket_type_id are real FKs into EventCatalog's own tables, cascade on
 * delete, the same posture and reasoning report_daily_sales already
 * takes on its own two columns (EventCatalog ships no delete endpoint
 * for either table, so the clause never fires in production, but
 * cascade avoids forcing every CheckIn test fixture's teardown to list
 * this table before deleting events or ticket_types).
 *
 * first_scan_at and last_scan_at are nullable: a cell whose only
 * increment so far is a DuplicateScanDetected (a duplicate for a
 * different ticket of the same event and ticket type arriving before
 * any accepted scan in that cell) carries no scan timestamp of its own.
 * ProjectEventAttendance (task 11) keeps both bounds commutative under
 * unordered delivery and replay with LEAST/GREATEST in the ON CONFLICT
 * DO UPDATE, per this table's own Data model note; Postgres's LEAST and
 * GREATEST ignore NULL arguments (returning NULL only when every
 * argument is NULL), so a NULL-carrying DuplicateScanDetected increment
 * never overwrites an already-set bound. unique(tenant_id, event_id,
 * ticket_type_id) is the ON CONFLICT DO UPDATE target that projector
 * upserts against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_event_attendance', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ticket_type_id')->constrained()->cascadeOnDelete();
            $table->integer('checked_in_count')->default(0);
            $table->integer('duplicate_scan_count')->default(0);
            $table->timestampTz('first_scan_at')->nullable();
            $table->timestampTz('last_scan_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['tenant_id', 'event_id', 'ticket_type_id']);
        });

        Rls::applyTenantPolicies('report_event_attendance');
    }

    public function down(): void
    {
        Schema::dropIfExists('report_event_attendance');
    }
};
