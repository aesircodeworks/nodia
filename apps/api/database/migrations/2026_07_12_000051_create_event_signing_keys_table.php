<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * event_signing_keys: versioned per-event QR signing keys, owned by
 * Orders and co-located with QR generation (stage-09 plan, Data model
 * "event_signing_keys"). secret is encrypted at rest via the model's
 * encrypted cast; this migration stores it as text. The unique index on
 * (event_id, key_version) enforces monotonic versions per event; the
 * partial unique index on event_id where status = 'active' is the
 * structural half of the single-active-key invariant, backstopping the
 * rotation Action's conditional UPDATE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_signing_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('event_id')->constrained();
            $table->integer('key_version');
            $table->text('secret');
            $table->string('status');
            $table->timestampTz('activated_at');
            $table->timestampTz('retired_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['event_id', 'key_version']);
        });

        DB::statement("create unique index event_signing_keys_active_event_idx on event_signing_keys (event_id) where status = 'active'");

        Rls::applyTenantPolicies('event_signing_keys');
    }

    public function down(): void
    {
        Schema::dropIfExists('event_signing_keys');
    }
};
