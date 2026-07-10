<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive EventCatalog migration (stage-05b plan, Data model:
 * "events.seat_map_id (additive migration)"): the nullable link from a
 * physical event to the seating template it was built from. On delete
 * restrict (Laravel's default for constrained(), no cascadeOnDelete()
 * call) so a template an event has selected cannot be silently deleted;
 * a later task in this stage maps the resulting FK violation to the
 * catalog.seat_map_in_use 409 problem on DELETE /v1/seat-maps/{seat_map}.
 *
 * No RLS change: events already carries its policy from the stage-05a
 * events migration, and merged migrations are never edited
 * (data-conventions), so this linkage ships as its own migration rather
 * than editing that one.
 *
 * The application invariant (a seat map must belong to the event's venue,
 * a virtual event cannot set one) is enforced in
 * App\EventCatalog\Actions\UpdateEvent, not by a CHECK constraint here:
 * unlike events_venue_or_url (a same-row invariant a CHECK can express),
 * "belongs to the event's venue" is a cross-table comparison a Postgres
 * CHECK constraint cannot express without a trigger, which this stage's
 * plan does not call for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->foreignUuid('seat_map_id')->nullable()->after('venue_id')->constrained();
            $table->index('seat_map_id');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('seat_map_id');
        });
    }
};
