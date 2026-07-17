<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * check_ins: one row per scan attempt, accepted or duplicate
 * (system-design 8.3 CHECK_IN; stage-09 plan, Data model "check_ins").
 * event_id is denormalized beyond the ERD, the same rationale as
 * tickets.event_id (stage-07 tickets migration): manifest overlays and
 * event-scoped queries stay single-table for the CheckIn context. The
 * partial unique index on ticket_id where result = 'accepted' is the
 * structural half of the first-scan-wins invariant; the (tenant_id,
 * device_id, client_scan_id) unique index backs online replay and
 * offline batch idempotence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_ins', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('ticket_id')->constrained();
            $table->foreignUuid('event_id')->constrained();
            $table->foreignUuid('user_id')->constrained();
            $table->string('device_id');
            $table->uuid('client_scan_id');
            $table->string('result');
            $table->timestampTz('scanned_at');
            $table->timestampTz('synced_at');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['tenant_id', 'device_id', 'client_scan_id']);
            $table->index(['event_id', 'synced_at']);
        });

        DB::statement("create unique index check_ins_accepted_ticket_idx on check_ins (ticket_id) where result = 'accepted'");

        Rls::applyTenantPolicies('check_ins');
    }

    public function down(): void
    {
        Schema::dropIfExists('check_ins');
    }
};
