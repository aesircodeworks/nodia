<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The buyer's seat selection order within a hold: seat reloads for
     * ticket issuance order by it so attendee names pair to the seats
     * the buyer listed them against, instead of physical row order.
     * Null whenever hold_id is null.
     */
    public function up(): void
    {
        Schema::table('event_seats', function (Blueprint $table): void {
            $table->smallInteger('hold_claim_position')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_seats', function (Blueprint $table): void {
            $table->dropColumn('hold_claim_position');
        });
    }
};
