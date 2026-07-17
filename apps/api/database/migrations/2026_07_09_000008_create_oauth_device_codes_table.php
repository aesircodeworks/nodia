<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passport's published migration, adjusted so user_id matches the uuid
 * primary key shared by `users` and `customers` (stage-03 plan,
 * data-conventions Tenancy exception). The device grant is unused this
 * stage (ADR 011 keeps third-party clients for post-launch); the table
 * ships for parity with Passport's expectations. No tenant_id and no
 * RLS, same rationale as the sibling oauth tables in this migration set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_device_codes', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->foreignUuid('client_id')->index();
            $table->char('user_code', 8)->unique();
            $table->text('scopes');
            $table->boolean('revoked');
            $table->dateTime('user_approved_at')->nullable();
            $table->dateTime('last_polled_at')->nullable();
            $table->dateTime('expires_at')->nullable();
        });

        Rls::grantUnscoped('oauth_device_codes');
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_device_codes');
    }

    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
