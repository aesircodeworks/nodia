<?php

namespace App\Payments\Http\Controllers;

use App\Payments\Actions\IngestGatewayWebhook;
use App\Payments\Gateways\GatewayRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /v1/webhooks/{gateway} (stage-08a plan, Endpoints; system-design
 * 3.2: one webhook route per registered adapter). Unauthenticated and
 * exempt from tenant resolution: the adapter's signature verification
 * is the authentication, and persistence runs under the sentinel
 * platform tenant.
 */
class WebhookController
{
    public function __invoke(
        Request $request,
        string $gateway,
        GatewayRegistry $gateways,
        IngestGatewayWebhook $ingest,
    ): Response {
        $adapter = $gateways->get($gateway) ?? throw new NotFoundHttpException;

        $headers = array_map(
            fn (array $values): string => (string) ($values[0] ?? ''),
            $request->headers->all(),
        );

        $ingest($adapter, $request->getContent(), $headers);

        return response()->noContent(200);
    }
}
