<?php

use App\Payments\Actions\PaginateLedgerEntriesForExport;
use App\Payments\Data\LedgerEntryExportRowData;
use App\Payments\Enums\LedgerAccount;
use App\Payments\Enums\LedgerDirection;
use App\Reporting\Support\Export\Sources\LedgerEntriesExportSource;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, task 15/T12: "one unit test per source in
 * tests/Unit/Reporting/ mapping Data objects to CSV columns and proving
 * the cursor iterator pages rather than loading the whole set."
 */

it('maps ledger entry export rows to their CSV columns, splitting amount into minor units and currency', function (): void {
    $source = new LedgerEntriesExportSource(new PaginateLedgerEntriesForExport);

    $row = new LedgerEntryExportRowData(
        id: 'entry-1',
        account: LedgerAccount::TenantNet->value,
        direction: LedgerDirection::Credit->value,
        amount: Money::of(9_450, 'USD'),
        referenceType: 'payment',
        referenceId: 'payment-1',
        createdAt: '2026-07-10T12:00:00Z',
    );

    $columns = $source->columns();

    expect(array_map(fn (callable $extract) => $extract($row), $columns))->toBe([
        'id' => 'entry-1',
        'account' => 'tenant_net',
        'direction' => 'credit',
        'amount' => 9_450,
        'currency' => 'USD',
        'reference_type' => 'payment',
        'reference_id' => 'payment-1',
        'created_at' => '2026-07-10T12:00:00Z',
    ]);
});

it('declares event_id prohibited: ledger_entries carries no event scoping', function (): void {
    $source = new LedgerEntriesExportSource(new PaginateLedgerEntriesForExport);

    expect(fn () => validator(['event_id' => Str::uuid7()->toString()], $source->rules())->validate())
        ->toThrow(ValidationException::class);

    expect(fn () => validator(['from' => '2026-07-01', 'to' => '2026-07-31'], $source->rules())->validate())
        ->not->toThrow(ValidationException::class);
});

function ledgerExportRow(string $tenantId, array $overrides = []): array
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

it('pages ledger entries through PaginateLedgerEntriesForExport without loading the whole result set', function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
        for ($i = 0; $i < 5; $i++) {
            DB::table('ledger_entries')->insert(ledgerExportRow($tenantId));
        }

        $source = new LedgerEntriesExportSource(new PaginateLedgerEntriesForExport(perPage: 2));

        DB::enableQueryLog();
        $pages = $source->pages($tenantId, []);

        expect(DB::getQueryLog())->toHaveCount(0);

        $pages->current();
        expect(DB::getQueryLog())->toHaveCount(1)
            ->and($pages->current())->toHaveCount(2);

        $pages->next();
        expect(DB::getQueryLog())->toHaveCount(2)
            ->and($pages->current())->toHaveCount(2);

        $pages->next();
        expect(DB::getQueryLog())->toHaveCount(3)
            ->and($pages->current())->toHaveCount(1);

        $pages->next();
        expect(DB::getQueryLog())->toHaveCount(3)
            ->and($pages->valid())->toBeFalse();
    });

    DB::statement('truncate ledger_entries');

    app(TenantTransaction::class)->asPlatform(function () use ($tenantId): void {
        Tenant::query()->whereKey($tenantId)->delete();
    });
});
