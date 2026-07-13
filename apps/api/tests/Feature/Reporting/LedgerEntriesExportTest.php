<?php

use App\Models\User;
use App\Payments\Enums\LedgerAccount;
use App\Payments\Enums\LedgerDirection;
use App\Reporting\Enums\ExportStatus;
use App\Reporting\Enums\ExportType;
use App\Reporting\Jobs\BuildExportJob;
use App\Reporting\Models\Export;
use App\Reporting\Support\Export\ExportSourceRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, task 15/T12, TDD sequencing Slice 8, Feature (end to
 * end): "a completed [ledger_entries] export contains exactly the
 * expected CSV rows for the tenant and parameter window ... row_count
 * and completed_at are set", plus T12's own mandate that ledger_entries
 * is proven never to leak another tenant's rows.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Storage::fake('media');
});

afterEach(function (): void {
    DB::statement('truncate ledger_entries');

    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            foreach (['media', 'exports'] as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('users')->where('email', 'like', '%ledger-export-test.example')->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * @return array<string, mixed>
 */
function ledgerEntriesExportRow(string $tenantId, array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => $tenantId,
        'account' => LedgerAccount::TenantNet->value,
        'direction' => LedgerDirection::Credit->value,
        'amount' => 9_450,
        'currency' => 'USD',
        'reference_type' => 'payment',
        'reference_id' => Str::uuid7()->toString(),
        'source_event_id' => Str::uuid7()->toString(),
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

/**
 * One tenant with an included entry (inside the requested window) and
 * one outside it.
 *
 * @return array{tenantId: string, includedRow: array<string, mixed>, userId: string}
 */
function ledgerEntriesExportFixture(): array
{
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $userId = app(TenantTransaction::class)->asPlatform(
        fn () => User::factory()->create(['email' => 'requester-'.Str::uuid7()->toString().'@ledger-export-test.example'])->id,
    );

    $included = ledgerEntriesExportRow($tenantId, ['created_at' => '2026-07-10 12:00:00']);
    $outsideWindow = ledgerEntriesExportRow($tenantId, ['created_at' => '2026-06-01 00:00:00']);

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($included, $outsideWindow): void {
        DB::table('ledger_entries')->insert([$included, $outsideWindow]);
    });

    return ['tenantId' => $tenantId, 'includedRow' => $included, 'userId' => $userId];
}

/**
 * @return list<list<string>>
 */
function readLedgerEntriesExportCsv(Export $export): array
{
    $path = $export->getMedia('export_file')->first()->getPathRelativeToRoot();
    $contents = Storage::disk('media')->get($path);

    $lines = array_filter(explode("\n", trim($contents)));

    return array_map(str_getcsv(...), $lines);
}

it('completes a ledger_entries export containing exactly the CSV rows for the tenant and date window', function (): void {
    $fixture = ledgerEntriesExportFixture();

    $exportId = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): string => Export::factory()->create([
        'tenant_id' => $fixture['tenantId'],
        'type' => ExportType::LedgerEntries,
        'requested_by_user_id' => $fixture['userId'],
        'parameters' => [
            'from' => '2026-07-01T00:00:00Z',
            'to' => '2026-07-31T23:59:59Z',
        ],
    ])->id);

    BuildExportJob::dispatch($exportId);

    app(TenantTransaction::class)->asTenant($fixture['tenantId'], function () use ($exportId, $fixture): void {
        $export = Export::query()->findOrFail($exportId);

        expect($export->status)->toBe(ExportStatus::Completed)
            ->and($export->row_count)->toBe(1)
            ->and($export->completed_at)->not->toBeNull()
            ->and($export->failure_code)->toBeNull();

        $included = $fixture['includedRow'];

        expect(readLedgerEntriesExportCsv($export))->toBe([
            ['id', 'account', 'direction', 'amount', 'currency', 'reference_type', 'reference_id', 'created_at'],
            [
                $included['id'], 'tenant_net', 'credit', '9450', 'USD',
                'payment', $included['reference_id'], '2026-07-10T12:00:00Z',
            ],
        ]);
    });
});

it('never includes another tenant\'s ledger entries', function (): void {
    $fixtureA = ledgerEntriesExportFixture();
    $fixtureB = ledgerEntriesExportFixture();

    $exportId = app(TenantTransaction::class)->asTenant($fixtureA['tenantId'], fn (): string => Export::factory()->create([
        'tenant_id' => $fixtureA['tenantId'],
        'type' => ExportType::LedgerEntries,
        'requested_by_user_id' => $fixtureA['userId'],
        'parameters' => [
            'from' => '2026-07-01T00:00:00Z',
            'to' => '2026-07-31T23:59:59Z',
        ],
    ])->id);

    BuildExportJob::dispatch($exportId);

    app(TenantTransaction::class)->asTenant($fixtureA['tenantId'], function () use ($exportId, $fixtureA, $fixtureB): void {
        $export = Export::query()->findOrFail($exportId);

        expect($export->status)->toBe(ExportStatus::Completed)
            ->and($export->row_count)->toBe(1);

        $rows = readLedgerEntriesExportCsv($export);
        $ids = array_column(array_slice($rows, 1), 0);

        expect($ids)->toBe([$fixtureA['includedRow']['id']])
            ->and($ids)->not->toContain($fixtureB['includedRow']['id']);
    });
});

it('rejects an export creation attempt carrying event_id in its parameters through the source\'s own validation rules', function (): void {
    $source = app(ExportSourceRegistry::class)->get(ExportType::LedgerEntries);

    expect(fn () => validator(['event_id' => Str::uuid7()->toString()], $source->rules())->validate())
        ->toThrow(ValidationException::class);
});
