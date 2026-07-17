<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * exports: the export lifecycle record (stage-11 plan, Data model
 * "exports"). The generated file itself is a medialibrary attachment on
 * this model (data-conventions: file attachments go through
 * medialibrary's media table, no bespoke path column here), added by a
 * later task once BuildExport exists. type and status are strings backed
 * by App\Reporting\Enums\ExportType and ExportStatus, the same posture
 * orders.status takes against App\Orders\Enums\OrderStatus: validated at
 * the application layer, not a database check constraint. parameters is
 * jsonb, validated per type by the request Data class a later task adds.
 * requested_by_user_id is a real FK into users (mirroring check_ins'
 * user_id), since export creation is activity-logged with the requesting
 * staff user as causer. The (tenant_id, created_at) index backs the
 * cursor-paginated list endpoint (task 16); there is no natural business
 * key to make unique, unlike the three projection tables, so this
 * migration carries no unique constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->string('type');
            $table->string('status')->default('pending');
            $table->jsonb('parameters');
            $table->foreignUuid('requested_by_user_id')->constrained('users');
            $table->integer('row_count')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->index(['tenant_id', 'created_at']);
        });

        Rls::applyTenantPolicies('exports');
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
