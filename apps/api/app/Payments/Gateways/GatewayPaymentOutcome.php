<?php

namespace App\Payments\Gateways;

enum GatewayPaymentOutcome: string
{
    case Approved = 'approved';
    case Declined = 'declined';
    case Pending = 'pending';
}
