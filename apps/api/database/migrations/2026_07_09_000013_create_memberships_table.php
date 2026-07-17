<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * memberships links a user to a tenant with a role (system-design 5.1,
 * stage-03 plan Data model). Standard single-table RLS through the shared
 * helper: no platform write policy, unlike tenant_domains, because
 * membership mutation is tenant admin surface (a future InviteUser or
 * AssignRole Action running under nodia_app inside the acting tenant's own
 * transaction), not platform-admin surface. A second permissive SELECT
 * policy, memberships_self_read, is layered on top for GET /v1/me: it
 * matches app.user_id, a second SET LOCAL setting the auth layer sets on
 * every staff request (arrives with a later Stage 3 task), independent of
 * any tenant context, so a bearer-only request without X-Tenant-Id can
 * still list exactly its own memberships across every tenant. Postgres
 * ORs multiple permissive policies together for the same command, so this
 * only ever widens SELECT visibility beyond the base tenant_isolation
 * policy, never past it for INSERT, UPDATE, or DELETE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained();
            $table->foreignUuid('tenant_id')->constrained();
            $table->foreignUuid('role_id')->constrained();
            $table->string('scope');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['user_id', 'tenant_id']);
            $table->index('tenant_id');
        });

        Rls::applyTenantPolicies('memberships');

        DB::statement(<<<'SQL'
            create policy memberships_self_read on memberships
                for select
                using (user_id = nullif(current_setting('app.user_id', true), '')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
