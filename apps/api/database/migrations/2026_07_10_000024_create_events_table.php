<?php

use App\EventCatalog\Enums\EventStatus;
use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * events: the publishable catalog entity (stage-05a plan, Data model,
 * task breakdown item 4, TDD slice 2). venue_id is nullable because
 * virtual events have no venue (system-design 8.3); name and description
 * are translatable jsonb columns Spatie\Translatable\HasTranslations owns
 * on the model (never cast to 'array' there, see the model's own
 * docblock). timezone is stored as data, never encoded into the UTC
 * start_at/end_at timestamps (data-conventions). async_payment_policy is
 * non-null: App\EventCatalog\Data\AsyncPaymentPolicyData's own
 * constructor defaults (slow_methods_enabled true, low_inventory_cutoff
 * null) mean a caller can always produce a valid value, so no column
 * default is needed here.
 *
 * Two CHECK constraints back invariants the request layer (a later task's
 * CreateEventData/UpdateEventData) validates first:
 *
 * - events_end_after_start: end_at strictly after start_at.
 * - events_venue_or_url: exactly one of venue_id or virtual_event_url,
 *   matching is_virtual (stage-05a plan, Data model, the constraint
 *   literally quoted there). App\EventCatalog\Models\Event's own saving
 *   hook (Event::assertVenueOrUrlInvariant) is the same invariant's
 *   application-level backstop, mirroring
 *   App\Identity\Models\Membership::assertScopeInvariant's precedent.
 *
 * Standard single-table RLS through the shared helper with no platform
 * write policy, mirroring venues: event mutation is tenant admin surface
 * (events.manage/events.publish gated), not platform-admin surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('venue_id')->nullable()->constrained();
            $table->string('status')->default(EventStatus::Draft->value);
            $table->jsonb('name');
            $table->jsonb('description');
            $table->timestampTz('start_at');
            $table->timestampTz('end_at');
            $table->string('timezone');
            $table->boolean('is_virtual')->default(false);
            $table->string('virtual_event_url')->nullable();
            $table->jsonb('async_payment_policy');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'start_at']);
            $table->index('venue_id');
        });

        DB::statement('alter table events add constraint events_end_after_start check (end_at > start_at)');

        DB::statement(<<<'SQL'
            alter table events add constraint events_venue_or_url check (
                (is_virtual and venue_id is null and virtual_event_url is not null)
                or
                (not is_virtual and venue_id is not null and virtual_event_url is null)
            )
            SQL);

        Rls::applyTenantPolicies('events');
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
