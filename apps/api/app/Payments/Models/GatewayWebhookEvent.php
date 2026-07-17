<?php

namespace App\Payments\Models;

use App\Payments\Enums\GatewayWebhookStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One raw webhook delivery as received (stage-08a plan, Data model
 * "gateway_webhook_events"). Rows carry the sentinel platform tenant;
 * every access runs under the sentinel tenant context.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $gateway
 * @property string $gateway_event_id
 * @property array<string, mixed>|null $payload
 * @property GatewayWebhookStatus $status
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $payload_pruned_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'gateway',
    'gateway_event_id',
    'payload',
    'status',
    'received_at',
    'processed_at',
    'payload_pruned_at',
])]
class GatewayWebhookEvent extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => GatewayWebhookStatus::class,
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'payload_pruned_at' => 'datetime',
        ];
    }
}
