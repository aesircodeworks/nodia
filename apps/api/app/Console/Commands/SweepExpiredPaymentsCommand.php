<?php

namespace App\Console\Commands;

use App\Payments\Actions\SweepExpiredPayments;
use Illuminate\Console\Command;

class SweepExpiredPaymentsCommand extends Command
{
    protected $signature = 'payments:expire';

    protected $description = 'Expire initiated payments past their confirmation window, recording PaymentExpired per row';

    public function handle(SweepExpiredPayments $sweep): int
    {
        $expired = $sweep();

        $this->info("Expired {$expired} payment(s).");

        return self::SUCCESS;
    }
}
