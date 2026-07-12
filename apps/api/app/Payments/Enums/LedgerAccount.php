<?php

namespace App\Payments\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The four ledger accounts of the per-payment breakdown (system-design
 * 7.3): gross charge receivable from the gateway, gateway fee, platform
 * commission, and the tenant net that Stage 8c pays out.
 */
#[TypeScript]
enum LedgerAccount: string
{
    case GatewayReceivable = 'gateway_receivable';
    case GatewayFees = 'gateway_fees';
    case PlatformCommission = 'platform_commission';
    case TenantNet = 'tenant_net';
}
