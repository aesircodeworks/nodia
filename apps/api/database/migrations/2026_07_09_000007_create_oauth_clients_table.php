<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passport's published migration (stage-03 plan). `id` is already uuid by
 * Passport's own default, matching data-conventions without adjustment.
 * `owner` is switched to a uuid morph so an owned client (device or
 * authorization-code flow, unused by the password-grant clients seeded
 * this stage) points at a uuid-keyed owner instead of a bigint one. No
 * tenant_id and no RLS, same rationale as the sibling oauth tables in
 * this migration set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->nullableUuidMorphs('owner');
            $table->string('name');
            $table->string('secret')->nullable();
            $table->string('provider')->nullable();
            $table->text('redirect_uris');
            $table->text('grant_types');
            $table->boolean('revoked');
            $table->timestamps();
        });

        Rls::grantUnscoped('oauth_clients');
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_clients');
    }

    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
