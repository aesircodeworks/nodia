<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive Payments migration (stage-12 plan, Data model "Additive
 * columns on existing tables"): the webhook payload pruner's own
 * marker. payload_pruned_at is nullable and starts NULL for every
 * existing and future row; App\Payments\Actions\PruneWebhookPayloads
 * nulls the payload column and stamps this column in one UPDATE for
 * rows past config('retention.webhook_payload_days'), never touching
 * the row's id or its unique (gateway, gateway_event_id), so webhook
 * idempotence outlives the retention window (system-design 14.3).
 * gateway_webhook_events.payload was created NOT NULL (stage-08a
 * migration, merged and never edited); pruning requires nulling it, so
 * this migration also drops that constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_webhook_events', function (Blueprint $table): void {
            $table->timestampTz('payload_pruned_at')->nullable()->after('payload');
        });

        DB::statement('alter table gateway_webhook_events alter column payload drop not null');
    }

    public function down(): void
    {
        DB::statement('alter table gateway_webhook_events alter column payload set not null');

        Schema::table('gateway_webhook_events', function (Blueprint $table): void {
            $table->dropColumn('payload_pruned_at');
        });
    }
};
