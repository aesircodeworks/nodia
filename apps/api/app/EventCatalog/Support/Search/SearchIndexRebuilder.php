<?php

namespace App\EventCatalog\Support\Search;

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Actions\ListTenantIds;
use Illuminate\Support\Facades\DB;

/**
 * Backs php artisan search:rebuild (stage-05c plan, task breakdown item
 * 10, Domain events: search:rebuild scans published events directly
 * instead of rescanning the outbox, because search documents derive
 * entirely from current event state). Iterates every tenant and, for
 * each, opens a real tenant transaction (App\Support\Tenancy\
 * TenantTransaction::asTenant) rather than bypassing RLS: the tenant
 * listing itself is the only cross-tenant read, done under the platform
 * posture the same way App\Support\Outbox\OutboxSweeper's own
 * cross-tenant scan is, and every event read and document write below it
 * runs scoped to one tenant at a time.
 *
 * Every one of a tenant's existing rows is deleted before it is rebuilt
 * from currently published events, rather than relying solely on
 * SearchDocumentWriter::upsert()'s ON CONFLICT to refresh what a
 * published event already has a row for: that upsert alone would never
 * remove a row belonging to an event that has since moved to draft or
 * canceled, so the command would not converge on the same state a
 * truncate-then-rebuild produces if it were run over an
 * already-populated table.
 */
final readonly class SearchIndexRebuilder
{
    public function __construct(
        private TenantTransaction $transactions,
        private ListTenantIds $listTenantIds,
        private EventSearchDocumentBuilder $builder,
        private SearchDocumentWriter $writer,
    ) {}

    /**
     * @return int Number of tenants rebuilt
     */
    public function rebuild(): int
    {
        $tenantIds = $this->transactions->asPlatform(fn () => ($this->listTenantIds)());

        foreach ($tenantIds as $tenantId) {
            $this->rebuildTenant($tenantId);
        }

        return $tenantIds->count();
    }

    private function rebuildTenant(string $tenantId): void
    {
        $this->transactions->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('event_search_documents')->where('tenant_id', $tenantId)->delete();

            Event::query()->where('tenant_id', $tenantId)->where('status', EventStatus::Published)->cursor()
                ->each(function (Event $event): void {
                    foreach ($this->builder->documentsFor($event) as $row) {
                        $this->writer->upsert($row);
                    }
                });
        });
    }
}
