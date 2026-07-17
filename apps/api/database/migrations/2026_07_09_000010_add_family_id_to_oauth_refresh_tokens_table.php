<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom column, not part of Passport's published schema: family_id
 * threads every refresh token issued from one login through every
 * rotation that follows it, so reuse of an already-rotated token can
 * revoke every live descendant in one sweep (stage-03 plan, Slice 2;
 * Risks "Reuse detection semantics"). The first refresh token in a family
 * carries its own id as family_id; each rotation copies the id forward
 * (App\Identity\OAuth\IdentityRefreshTokenRepository). No default value:
 * this table is authentication infrastructure with no production traffic
 * yet (stage-03 is still landing), so a NOT NULL column with no backfill
 * is safe here; a populated local database needs `make fresh`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_refresh_tokens', function (Blueprint $table) {
            $table->uuid('family_id')->after('access_token_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('oauth_refresh_tokens', function (Blueprint $table) {
            $table->dropColumn('family_id');
        });
    }

    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
