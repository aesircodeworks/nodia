<?php

namespace App\Payments\Actions;

use App\Payments\Enums\GatewayWebhookStatus;
use App\Payments\Gateways\GatewayAdapter;
use App\Payments\Models\GatewayWebhookEvent;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The raw ingestion path (stage-08a plan, Slice 5; system-design 7.4):
 * verify the signature, persist under the sentinel platform tenant, and
 * treat an insert conflict on (gateway, gateway_event_id) as a success
 * that reuses the existing row. After persist, nothing here fails:
 * processing is the queued job's problem and gateway retries land on
 * the unique key.
 */
final class IngestGatewayWebhook
{
    public function __construct(
        private readonly TenantTransaction $tenantTransaction,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @return string the persisted (or pre-existing) raw row id
     */
    public function __invoke(GatewayAdapter $adapter, string $body, array $headers): string
    {
        $parsed = $adapter->parseWebhook($body, $headers);

        return $this->tenantTransaction->asTenant(
            config()->string('tenancy.platform_tenant_id'),
            function () use ($adapter, $parsed): string {
                try {
                    return DB::transaction(fn (): string => GatewayWebhookEvent::query()->create([
                        'tenant_id' => config()->string('tenancy.platform_tenant_id'),
                        'gateway' => $adapter->identifier(),
                        'gateway_event_id' => $parsed->gatewayEventId,
                        'payload' => $parsed->payload,
                        'status' => GatewayWebhookStatus::Received,
                        'received_at' => Date::now(),
                    ])->id);
                } catch (UniqueConstraintViolationException) {
                    return GatewayWebhookEvent::query()
                        ->where('gateway', $adapter->identifier())
                        ->where('gateway_event_id', $parsed->gatewayEventId)
                        ->firstOrFail()
                        ->id;
                }
            },
        );
    }
}
