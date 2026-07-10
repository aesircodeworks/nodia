<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * media: spatie/laravel-medialibrary's published migration (vendor/spatie/
 * laravel-medialibrary/database/migrations/create_media_table.php.stub,
 * installed version 11.23.2, verified compatible with laravel/framework
 * ^13.8 per the package's current requirements docs, PHP 8.2+ and Laravel
 * 10+) adjusted for UUID keys and a non-null tenant_id under RLS
 * (stage-05c plan, Data model; ADR 014).
 *
 * Two adjustments from the stub, both keyed to this codebase's own
 * conventions rather than the package's incrementing-id defaults:
 * - id: uuid primary key populated by App\Support\Media\Models\Media's
 *   HasUuids trait, replacing the stub's auto-incrementing bigint
 *   (data-conventions "no auto-increment columns").
 * - model (uuidMorphs instead of morphs): every polymorphic owner this
 *   stage and Stage 8a attach media to (Event, Tenant, later Ticket) has
 *   a uuid primary key, so model_id must be uuid too, not the stub's
 *   unsignedBigInteger. uuidMorphs() ships the same composite
 *   (model_type, model_id) index the stub's morphs() would have.
 *
 * uuid (the package's own separate public identifier column, populated by
 * Spatie\MediaLibrary\MediaCollections\Models\Concerns\HasUuid, unrelated
 * to this table's id) is kept exactly as the stub defines it: nullable,
 * unique. The package's URL and conversion machinery reads and writes it
 * directly, so narrowing it is not this migration's adjustment to make.
 *
 * tenant_id carries a real foreign key to tenants, like every other
 * tenant-scoped domain table (venues, ticket_types), because a media row
 * always belongs to exactly one tenant regardless of which polymorphic
 * model owns it. platformWrite is enabled: Stage 5c task 5's tenant
 * branding logo upload runs entirely under the platform posture
 * (asPlatform(), mirroring every /v1/tenants/{tenant} mutation,
 * system-design 4.3), where app.tenant_id is the platform sentinel
 * tenant, never the target tenant, so a Media row stamped with the real
 * owning tenant's id can only pass the tenant_isolation policy's WITH
 * CHECK through the platform write policy, the same shape
 * tenants_platform_write already establishes. This migration cannot be
 * edited once merged (data-conventions Migrations), so the policy ships
 * now even though the upload endpoint itself is a later task.
 *
 * created_at/updated_at are non-null timestampTz columns, not the stub's
 * nullableTimestamps(), matching every other table's own convention
 * (data-conventions "Timestamps are stored in UTC"); Eloquent always
 * supplies both on create.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained();
            $table->uuidMorphs('model');
            $table->uuid('uuid')->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->jsonb('manipulations');
            $table->jsonb('custom_properties');
            $table->jsonb('generated_conversions');
            $table->jsonb('responsive_images');
            $table->unsignedInteger('order_column')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->index('tenant_id');
            $table->index('order_column');
        });

        Rls::applyTenantPolicies('media', platformWrite: true);
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
