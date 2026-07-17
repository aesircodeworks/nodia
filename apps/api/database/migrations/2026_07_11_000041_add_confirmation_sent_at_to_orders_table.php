<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * orders.confirmation_sent_at (stage-08a plan, Data model "Additive
 * changes"): TicketIssued is recorded per ticket, so an order with
 * three tickets yields three events, and the confirmation email must
 * send once per order. The consumer claims the send with a conditional
 * UPDATE where confirmation_sent_at IS NULL and only the claim winner
 * sends; outbox delivery tracking alone cannot provide this because
 * each ticket event has a distinct event ID.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestampTz('confirmation_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('confirmation_sent_at');
        });
    }
};
