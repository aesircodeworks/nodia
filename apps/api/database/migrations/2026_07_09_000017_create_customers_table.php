<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * customers: attendee identities, tenant-scoped, unique per tenant by
 * email so the same address holds independent accounts under different
 * tenants (stage-03 plan Data model; system-design 5.2). `password` is
 * nullable for guest checkout, claimed later via email verification
 * (ADR 007); `locale` is nullable and falls back to the tenant default
 * (system-design 12). `anonymized_at` ships now, unused until Stage 12's
 * erasure flow, so this schema never needs a later alteration (stage-03
 * plan Non-goals). RLS is the plain Rls::applyTenantPolicies posture, no
 * platform write: the plan calls this "standard single-table policy," and
 * customer mutation belongs to the storefront-facing RegisterCustomer and
 * ClaimGuestAccount Actions (a later Stage 3 task) running under
 * nodia_app inside the acting tenant's own transaction, not
 * platform-admin surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->string('email');
            $table->string('name');
            $table->string('password')->nullable();
            $table->string('locale')->nullable();
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('anonymized_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['tenant_id', 'email']);
        });

        Rls::applyTenantPolicies('customers');
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
