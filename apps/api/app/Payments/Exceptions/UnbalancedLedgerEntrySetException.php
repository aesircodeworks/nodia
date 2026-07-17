<?php

namespace App\Payments\Exceptions;

use RuntimeException;

/**
 * Raised by the entry-set builder before anything touches the database:
 * an unbalanced set can only come from a caller bug (fee plus commission
 * exceeding gross, or a returned commission exceeding the refund), and
 * writing it would corrupt the books rather than fail loudly.
 */
final class UnbalancedLedgerEntrySetException extends RuntimeException
{
    public static function forPayment(int $gross, int $fee, int $commission): self
    {
        return new self("Unbalanced payment entry set: fee {$fee} plus commission {$commission} exceeds gross {$gross}.");
    }

    public static function forRefund(int $amount, int $returnedCommission): self
    {
        return new self("Unbalanced refund entry set: returned commission {$returnedCommission} exceeds refund amount {$amount}.");
    }
}
