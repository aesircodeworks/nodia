<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * check_in_assignments: the event-scoping layer for check-in roles
 * (stage-09 plan, Data model "check_in_assignments"). A role holding
 * checkin.manage bypasses this table entirely; a role holding only
 * checkin.scan must have a row here for the target event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_in_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('event_id')->constrained();
            $table->foreignUuid('user_id')->constrained();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['tenant_id', 'event_id', 'user_id']);
        });

        Rls::applyTenantPolicies('check_in_assignments');
    }

    public function down(): void
    {
        Schema::dropIfExists('check_in_assignments');
    }
};
