<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * event_search_documents: the full-text search projection, owned by
 * EventCatalog (stage-05c plan, Data model, task breakdown item 6, TDD
 * sequencing Slice 5). One row per published event per tenant-supported
 * locale; App\EventCatalog\Support\Search\EventSearchDocumentBuilder
 * (this task) resolves the locale-fallback content and the
 * locale-to-regconfig mapping, and the RefreshSearchIndex consumer (a
 * later task in this stage) upserts rows on (event_id, locale) as events
 * from the outbox arrive.
 *
 * tenant_id is denormalized (system-design 4.2), matching every other
 * tenant-scoped table's own posture; event_id cascades on delete (a
 * deleted event owns no orphaned search rows; nothing else in this
 * codebase hard-deletes an event, but the FK still documents the
 * ownership). name and description are the resolved, locale-fallback
 * content the vector was built from, kept for debuggability and rebuild
 * verification (stage-05c plan, Data model). event_starts_at is
 * denormalized from the event so ranking ties break on it without a join
 * in the ORDER BY.
 *
 * search_vector is tsvector, not a generated column: to_tsvector(regconfig,
 * text) with a column-derived regconfig is not immutable, so Postgres
 * refuses it as a GENERATED ALWAYS expression. It ships here as a raw
 * ALTER TABLE statement because Schema\Blueprint has no tsvector column
 * type; it is written by the projector on every insert (NOT NULL, no
 * default, so every write must supply it explicitly). The GIN index
 * likewise ships as a raw statement, custom-named
 * event_search_documents_search_vector_idx per data-conventions ("custom
 * names only when the builder cannot express the index").
 *
 * Unique (event_id, locale) is the key the projector's ON CONFLICT upsert
 * targets, which is what makes concurrent duplicate deliveries of the
 * same catalog event harmless (stage-05c plan, Domain events).
 *
 * Standard single-table RLS through the shared helper with no platform
 * write policy: search documents are maintained entirely by the
 * RefreshSearchIndex consumer and the search:rebuild command, both
 * running under a real tenant's own posture (asTenant), never the
 * platform posture, mirroring events and ticket_types.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_search_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->string('locale');
            $table->text('name');
            $table->text('description')->nullable();
            $table->timestampTz('event_starts_at');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['event_id', 'locale']);
            $table->index('tenant_id');
        });

        DB::statement('alter table event_search_documents add column search_vector tsvector not null');

        DB::statement('create index event_search_documents_search_vector_idx on event_search_documents using gin (search_vector)');

        Rls::applyTenantPolicies('event_search_documents');
    }

    public function down(): void
    {
        Schema::dropIfExists('event_search_documents');
    }
};
