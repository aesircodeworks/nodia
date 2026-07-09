<?php

namespace App\Support\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a virtual model attribute to Money backed by an integer minor-unit
 * column paired with the row's currency column (data-conventions Money).
 * The amount column defaults to "{attribute}_amount"; tables whose row is a
 * single monetary fact pass their bare column name as the first cast
 * parameter, e.g. "price => MoneyCast::class.':amount'".
 *
 * @implements CastsAttributes<Money|null, mixed>
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(
        private readonly ?string $amountColumn = null,
        private readonly string $currencyColumn = 'currency',
    ) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $amount = $attributes[$this->amountColumn($key)] ?? null;

        if ($amount === null) {
            return null;
        }

        return Money::of((int) $amount, (string) ($attributes[$this->currencyColumn] ?? ''));
    }

    /**
     * @return array<string, int|string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$this->amountColumn($key) => null];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException(sprintf('The [%s] attribute only accepts %s instances or null.', $key, Money::class));
        }

        $rowCurrency = $attributes[$this->currencyColumn] ?? null;

        if ($rowCurrency !== null && $rowCurrency !== $value->currency) {
            throw CurrencyMismatchException::between((string) $rowCurrency, $value->currency);
        }

        return [
            $this->amountColumn($key) => $value->amount,
            $this->currencyColumn => $value->currency,
        ];
    }

    private function amountColumn(string $key): string
    {
        return $this->amountColumn ?? "{$key}_amount";
    }
}
