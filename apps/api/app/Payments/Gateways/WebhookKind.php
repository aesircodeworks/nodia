<?php

namespace App\Payments\Gateways;

enum WebhookKind: string
{
    case Confirmed = 'confirmed';
    case Failed = 'failed';
}
