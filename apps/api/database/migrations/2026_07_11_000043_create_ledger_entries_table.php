<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ledger_entries: append-only double-entry legs projected from outbox
 * events (system-design 7.3, 8.3; stage-08b plan Data model
 * "ledger_entries"). The row is a single monetary fact, so the
 * principal value is bare amount paired with currency
 * (data-conventions Money exception). Append-only is a database
 * guarantee, not an application convention: the trigger raises on
 * UPDATE and DELETE for every role, and corrections are new entries.
 * The (source_event_id, account) unique index is the idempotence
 * anchor for the projection; (tenant_id, currency, account) backs the
 * balance sums; created_at with id backs the deterministic cursor
 * order of the read endpoint.
 *
 * tenant_id is a plain uuid column, deliberately without a foreign key
 * constraint to tenants, for the reason the activity_log migration
 * spells out: the append-only trigger denies DELETE to every role, so
 * a real foreign key would make any tenant a ledger row references
 * permanently undeletable, defeating the isolation suite's shared
 * TenantFixture teardown. A financial ledger is meant to outlive the
 * records it describes, so the missing edge is the correct posture,
 * not merely a test accommodation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('account');
            $table->string('direction');
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('reference_type');
            $table->uuid('reference_id');
            $table->uuid('source_event_id');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['source_event_id', 'account']);
            $table->index(['tenant_id', 'currency', 'account']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('created_at');
        });

        DB::statement('alter table ledger_entries add constraint ledger_entries_amount_positive check (amount > 0)');

        DB::unprepared(<<<'SQL'
            create or replace function ledger_entries_append_only() returns trigger
            language plpgsql as $$
            begin
                raise exception 'ledger_entries is append-only: corrections are new entries';
            end
            $$;

            create trigger ledger_entries_append_only
                before update or delete on ledger_entries
                for each row execute function ledger_entries_append_only();
            SQL);

        Rls::applyTenantPolicies('ledger_entries');
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        DB::statement('drop function if exists ledger_entries_append_only()');
    }
};
