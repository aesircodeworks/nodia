<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * gateway_webhook_events: the platform-scoped raw webhook log
 * (stage-08a plan, Data model; system-design 7.4: persist first, always
 * 2xx after persist, process asynchronously). A webhook arrives with no
 * tenant context, so rows carry the sentinel platform tenant
 * (data-conventions Tenancy); the tenant-scoped effect lives on
 * payments and orders. The (gateway, gateway_event_id) unique makes
 * duplicate deliveries insert-conflict into a no-op reusing the
 * existing row, the idempotence anchor for the whole ingestion path.
 * The (status, received_at) index backs retention and stuck-event
 * sweeps (the retention window itself is Stage 12, system-design 14.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->string('gateway');
            $table->string('gateway_event_id');
            $table->jsonb('payload');
            $table->string('status')->default('received');
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['gateway', 'gateway_event_id']);
            $table->index(['status', 'received_at']);
        });

        DB::statement("alter table gateway_webhook_events add constraint gateway_webhook_events_status_check check (status in ('received', 'processed', 'ignored'))");

        Rls::applyTenantPolicies('gateway_webhook_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_webhook_events');
    }
};
