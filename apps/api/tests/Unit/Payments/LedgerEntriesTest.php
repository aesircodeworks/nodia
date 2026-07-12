<?php

use App\Payments\Enums\LedgerAccount;
use App\Payments\Enums\LedgerDirection;
use App\Payments\Enums\RefundCommissionPolicy;
use App\Payments\Exceptions\UnbalancedLedgerEntrySetException;
use App\Payments\Models\LedgerEntry;
use App\Payments\Support\LedgerEntrySetBuilder;
use App\Payments\Support\LedgerLeg;
use App\Support\Money\CurrencyMismatchException;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08b plan, Slice 3: the append-only trigger is a database
 * guarantee, the entry-set builder produces balanced leg sets for both
 * commission policies, and (source_event_id, account) uniqueness makes
 * re-insertion a no-op.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create()->id,
    );
});

afterEach(function (): void {
    DB::statement('truncate ledger_entries');

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

function ledgerRow(string $tenantId, array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => $tenantId,
        'account' => LedgerAccount::TenantNet->value,
        'direction' => LedgerDirection::Credit->value,
        'amount' => 1000,
        'currency' => 'USD',
        'reference_type' => 'payment',
        'reference_id' => Str::uuid7()->toString(),
        'source_event_id' => Str::uuid7()->toString(),
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

it('raises at the database level on UPDATE', function (): void {
    $row = ledgerRow($this->tenantId);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($row): void {
        DB::table('ledger_entries')->insert($row);
        DB::table('ledger_entries')->where('id', $row['id'])->update(['amount' => 2000]);
    });
})->throws(QueryException::class, 'append-only');

it('raises at the database level on DELETE', function (): void {
    $row = ledgerRow($this->tenantId);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($row): void {
        DB::table('ledger_entries')->insert($row);
        DB::table('ledger_entries')->where('id', $row['id'])->delete();
    });
})->throws(QueryException::class, 'append-only');

it('rejects a non-positive amount through the check constraint', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('ledger_entries')->insert(ledgerRow($this->tenantId, ['amount' => 0]));
    });
})->throws(QueryException::class);

it('makes duplicate insertion a no-op through the source event and account unique index', function (): void {
    $sourceEventId = Str::uuid7()->toString();

    $inserted = app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($sourceEventId): array {
        $first = DB::table('ledger_entries')->insertOrIgnore(
            ledgerRow($this->tenantId, ['source_event_id' => $sourceEventId]),
        );

        $second = DB::table('ledger_entries')->insertOrIgnore(
            ledgerRow($this->tenantId, ['source_event_id' => $sourceEventId, 'amount' => 999]),
        );

        return [$first, $second, DB::table('ledger_entries')->where('source_event_id', $sourceEventId)->count()];
    });

    expect($inserted)->toBe([1, 0, 1]);
});

describe('entry-set builder', function (): void {
    it('produces the four balanced payment legs', function (): void {
        $legs = (new LedgerEntrySetBuilder)->paymentLegs(
            Money::of(10_000, 'USD'),
            Money::of(300, 'USD'),
            Money::of(250, 'USD'),
        );

        $byAccount = collect($legs)->keyBy(fn (LedgerLeg $leg) => $leg->account->value);

        expect($legs)->toHaveCount(4)
            ->and($byAccount['gateway_receivable']->direction)->toBe(LedgerDirection::Debit)
            ->and($byAccount['gateway_receivable']->amount->amount)->toBe(10_000)
            ->and($byAccount['gateway_fees']->direction)->toBe(LedgerDirection::Credit)
            ->and($byAccount['gateway_fees']->amount->amount)->toBe(300)
            ->and($byAccount['platform_commission']->direction)->toBe(LedgerDirection::Credit)
            ->and($byAccount['platform_commission']->amount->amount)->toBe(250)
            ->and($byAccount['tenant_net']->direction)->toBe(LedgerDirection::Credit)
            ->and($byAccount['tenant_net']->amount->amount)->toBe(9_450);
    });

    it('omits zero-amount legs so the positive-amount check never trips', function (): void {
        $legs = (new LedgerEntrySetBuilder)->paymentLegs(
            Money::of(10_000, 'USD'),
            Money::of(0, 'USD'),
            Money::of(0, 'USD'),
        );

        expect(collect($legs)->map(fn (LedgerLeg $leg) => $leg->account->value)->all())
            ->toBe(['gateway_receivable', 'tenant_net']);
    });

    it('produces the returned-policy refund legs debiting both tenant net and platform commission', function (): void {
        $legs = (new LedgerEntrySetBuilder)->refundLegs(
            Money::of(5_000, 'USD'),
            Money::of(125, 'USD'),
            RefundCommissionPolicy::Returned,
        );

        $byAccount = collect($legs)->keyBy(fn (LedgerLeg $leg) => $leg->account->value);

        expect($legs)->toHaveCount(3)
            ->and($byAccount['gateway_receivable']->direction)->toBe(LedgerDirection::Credit)
            ->and($byAccount['gateway_receivable']->amount->amount)->toBe(5_000)
            ->and($byAccount['tenant_net']->direction)->toBe(LedgerDirection::Debit)
            ->and($byAccount['tenant_net']->amount->amount)->toBe(4_875)
            ->and($byAccount['platform_commission']->direction)->toBe(LedgerDirection::Debit)
            ->and($byAccount['platform_commission']->amount->amount)->toBe(125);
    });

    it('produces the retained-policy refund legs debiting only tenant net', function (): void {
        $legs = (new LedgerEntrySetBuilder)->refundLegs(
            Money::of(5_000, 'USD'),
            Money::of(0, 'USD'),
            RefundCommissionPolicy::Retained,
        );

        $byAccount = collect($legs)->keyBy(fn (LedgerLeg $leg) => $leg->account->value);

        expect($legs)->toHaveCount(2)
            ->and($byAccount['gateway_receivable']->direction)->toBe(LedgerDirection::Credit)
            ->and($byAccount['tenant_net']->direction)->toBe(LedgerDirection::Debit)
            ->and($byAccount['tenant_net']->amount->amount)->toBe(5_000);
    });

    it('balances every produced set per currency', function (array $legs): void {
        $debits = collect($legs)
            ->filter(fn (LedgerLeg $leg) => $leg->direction === LedgerDirection::Debit)
            ->sum(fn (LedgerLeg $leg) => $leg->amount->amount);

        $credits = collect($legs)
            ->filter(fn (LedgerLeg $leg) => $leg->direction === LedgerDirection::Credit)
            ->sum(fn (LedgerLeg $leg) => $leg->amount->amount);

        expect($debits)->toBe($credits);
    })->with([
        'payment' => fn () => (new LedgerEntrySetBuilder)->paymentLegs(Money::of(9_999, 'BRL'), Money::of(287, 'BRL'), Money::of(13, 'BRL')),
        'refund returned' => fn () => (new LedgerEntrySetBuilder)->refundLegs(Money::of(3_333, 'BRL'), Money::of(83, 'BRL'), RefundCommissionPolicy::Returned),
        'refund retained' => fn () => (new LedgerEntrySetBuilder)->refundLegs(Money::of(3_333, 'BRL'), Money::of(0, 'BRL'), RefundCommissionPolicy::Retained),
        'payout' => fn () => (new LedgerEntrySetBuilder)->payoutLegs(Money::of(4_444, 'BRL')),
    ]);

    it('rejects an unbalanced payment set where fee and commission exceed gross', function (): void {
        (new LedgerEntrySetBuilder)->paymentLegs(
            Money::of(100, 'USD'),
            Money::of(80, 'USD'),
            Money::of(30, 'USD'),
        );
    })->throws(UnbalancedLedgerEntrySetException::class);

    it('rejects a returned commission exceeding the refund amount', function (): void {
        (new LedgerEntrySetBuilder)->refundLegs(
            Money::of(100, 'USD'),
            Money::of(150, 'USD'),
            RefundCommissionPolicy::Returned,
        );
    })->throws(UnbalancedLedgerEntrySetException::class);

    it('rejects a currency mismatch across the set', function (): void {
        (new LedgerEntrySetBuilder)->paymentLegs(
            Money::of(1_000, 'USD'),
            Money::of(10, 'BRL'),
            Money::of(10, 'USD'),
        );
    })->throws(CurrencyMismatchException::class);

    it('produces the balanced payout pair debiting tenant net and crediting gateway receivable', function (): void {
        $legs = (new LedgerEntrySetBuilder)->payoutLegs(Money::of(9_450, 'USD'));

        $byAccount = collect($legs)->keyBy(fn (LedgerLeg $leg) => $leg->account->value);

        expect($legs)->toHaveCount(2)
            ->and($byAccount['tenant_net']->direction)->toBe(LedgerDirection::Debit)
            ->and($byAccount['tenant_net']->amount->amount)->toBe(9_450)
            ->and($byAccount['gateway_receivable']->direction)->toBe(LedgerDirection::Credit)
            ->and($byAccount['gateway_receivable']->amount->amount)->toBe(9_450);
    });
});

it('persists through the LedgerEntry model with enum casts', function (): void {
    $entry = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => LedgerEntry::query()->create([
        'tenant_id' => $this->tenantId,
        'account' => LedgerAccount::GatewayFees,
        'direction' => LedgerDirection::Credit,
        'amount' => 300,
        'currency' => 'USD',
        'reference_type' => 'payment',
        'reference_id' => Str::uuid7()->toString(),
        'source_event_id' => Str::uuid7()->toString(),
    ]));

    expect($entry->account)->toBe(LedgerAccount::GatewayFees)
        ->and($entry->direction)->toBe(LedgerDirection::Credit)
        ->and(Str::isUuid($entry->id))->toBeTrue();
});
