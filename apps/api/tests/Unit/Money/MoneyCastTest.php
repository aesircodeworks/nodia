<?php

use App\Support\Money\CurrencyMismatchException;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class MoneyCastProbe extends Model
{
    use HasUuids;

    protected $table = 'money_cast_probes';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['price' => MoneyCast::class];
    }
}

class BareAmountMoneyCastProbe extends Model
{
    use HasUuids;

    protected $table = 'bare_amount_money_cast_probes';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['principal' => MoneyCast::class.':amount'];
    }
}

beforeEach(function () {
    Schema::create('money_cast_probes', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->bigInteger('price_amount')->nullable();
        $table->char('currency', 3)->nullable();
    });

    Schema::create('bare_amount_money_cast_probes', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->bigInteger('amount')->nullable();
        $table->char('currency', 3)->nullable();
    });
});

afterEach(function () {
    Schema::dropIfExists('money_cast_probes');
    Schema::dropIfExists('bare_amount_money_cast_probes');
});

test('round-trips a Money attribute through price_amount and currency columns', function () {
    $probe = new MoneyCastProbe;
    $probe->price = Money::of(12500, 'BRL');
    $probe->save();

    $raw = (array) $probe->getConnection()->table('money_cast_probes')->first();

    expect((int) $raw['price_amount'])->toBe(12500)
        ->and($raw['currency'])->toBe('BRL');

    $fresh = MoneyCastProbe::query()->findOrFail($probe->getKey());

    expect($fresh->price)->toBeInstanceOf(Money::class)
        ->and($fresh->price->equals(Money::of(12500, 'BRL')))->toBeTrue();
});

test('reads null when the amount column is null', function () {
    $probe = MoneyCastProbe::query()->create(['price_amount' => null, 'currency' => null]);

    expect(MoneyCastProbe::query()->findOrFail($probe->getKey())->price)->toBeNull();
});

test('writing null clears the amount column', function () {
    $probe = new MoneyCastProbe;
    $probe->price = Money::of(12500, 'BRL');
    $probe->save();

    $probe->price = null;
    $probe->save();

    expect(MoneyCastProbe::query()->findOrFail($probe->getKey())->price)->toBeNull();
});

test('refuses to write a Money whose currency mismatches the already-set row currency', function () {
    $probe = new MoneyCastProbe;
    $probe->price = Money::of(12500, 'BRL');
    $probe->save();

    expect(fn () => $probe->price = Money::of(9900, 'USD'))
        ->toThrow(CurrencyMismatchException::class);
});

test('allows overwriting with a Money in the same currency', function () {
    $probe = new MoneyCastProbe;
    $probe->price = Money::of(12500, 'BRL');
    $probe->save();

    $probe->price = Money::of(9900, 'BRL');
    $probe->save();

    expect(MoneyCastProbe::query()->findOrFail($probe->getKey())->price->equals(Money::of(9900, 'BRL')))->toBeTrue();
});

test('refuses to write anything other than Money or null', function () {
    $probe = new MoneyCastProbe;

    expect(fn () => $probe->price = 12500)->toThrow(InvalidArgumentException::class);
});

test('supports the bare amount column exemption via a cast parameter', function () {
    $probe = new BareAmountMoneyCastProbe;
    $probe->principal = Money::of(9900, 'BRL');
    $probe->save();

    $raw = (array) $probe->getConnection()->table('bare_amount_money_cast_probes')->first();

    expect((int) $raw['amount'])->toBe(9900)
        ->and($raw['currency'])->toBe('BRL');

    $fresh = BareAmountMoneyCastProbe::query()->findOrFail($probe->getKey());

    expect($fresh->principal->equals(Money::of(9900, 'BRL')))->toBeTrue();
});
