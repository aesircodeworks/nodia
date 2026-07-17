<?php

use App\Payments\Actions\PaginateLedgerEntriesForExport;
use App\Payments\Data\LedgerEntryExportRowData;
use App\Payments\Enums\LedgerAccount;
use App\Payments\Enums\LedgerDirection;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, task 15/T12: "plus unit coverage of the three new
 * owning-context Actions in their own suites." Mirrors
 * PaginateOrdersForExport's own filter matrix, over LedgerEntry.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    DB::statement('truncate ledger_entries');

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

/**
 * @return array<string, mixed>
 */
function ledgerExportRowFixture(string $tenantId, array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => $tenantId,
        'account' => LedgerAccount::TenantNet->value,
        'direction' => LedgerDirection::Credit->value,
        'amount' => 1_000,
        'currency' => 'USD',
        'reference_type' => 'payment',
        'reference_id' => Str::uuid7()->toString(),
        'source_event_id' => Str::uuid7()->toString(),
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

it('maps every row to LedgerEntryExportRowData carrying its own account, direction, amount, and reference', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $row = ledgerExportRowFixture($this->tenantId, [
            'account' => LedgerAccount::PlatformCommission->value,
            'direction' => LedgerDirection::Debit->value,
            'amount' => 250,
            'currency' => 'BRL',
            'reference_type' => 'refund',
        ]);

        DB::table('ledger_entries')->insert($row);

        $paginate = new PaginateLedgerEntriesForExport;
        $rows = collect($paginate(null, null))->flatten(1);

        expect($rows)->toHaveCount(1);

        $result = $rows->first();

        expect($result)->toBeInstanceOf(LedgerEntryExportRowData::class)
            ->and($result->id)->toBe($row['id'])
            ->and($result->account)->toBe('platform_commission')
            ->and($result->direction)->toBe('debit')
            ->and($result->amount->amount)->toBe(250)
            ->and($result->amount->currency)->toBe('BRL')
            ->and($result->referenceType)->toBe('refund')
            ->and($result->referenceId)->toBe($row['reference_id']);
    });
});

it('bounds created_at inclusively on both ends', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $inside = ledgerExportRowFixture($this->tenantId, ['created_at' => '2026-07-10 12:00:00']);
        $before = ledgerExportRowFixture($this->tenantId, ['created_at' => '2026-06-01 00:00:00']);
        $after = ledgerExportRowFixture($this->tenantId, ['created_at' => '2026-08-01 00:00:00']);

        DB::table('ledger_entries')->insert([$inside, $before, $after]);

        $paginate = new PaginateLedgerEntriesForExport;
        $ids = collect($paginate('2026-07-01T00:00:00Z', '2026-07-31T23:59:59Z'))
            ->flatten(1)
            ->pluck('id')
            ->all();

        expect($ids)->toBe([$inside['id']])
            ->and($ids)->not->toContain($before['id'])
            ->and($ids)->not->toContain($after['id']);
    });
});

it('pages across a boundary in ascending id order, covering every row exactly once', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $rows = collect(range(1, 5))->map(fn () => ledgerExportRowFixture($this->tenantId));

        DB::table('ledger_entries')->insert($rows->all());

        $paginate = new PaginateLedgerEntriesForExport(perPage: 2);
        $pages = iterator_to_array($paginate(null, null));

        expect($pages)->toHaveCount(3)
            ->and(array_map(count(...), $pages))->toBe([2, 2, 1]);

        $ids = collect($pages)->flatten(1)->pluck('id')->all();

        expect($ids)->toBe($rows->pluck('id')->sort()->values()->all());
    });
});
