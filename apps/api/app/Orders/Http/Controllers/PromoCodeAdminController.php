<?php

namespace App\Orders\Http\Controllers;

use App\Orders\Actions\CreatePromoCode;
use App\Orders\Actions\UpdatePromoCode;
use App\Orders\Data\PromoCodeData;
use App\Orders\Data\UpsertPromoCodeData;
use App\Orders\Models\PromoCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Spatie\LaravelData\PaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The staff promo code surface (stage-07 plan, Endpoints), all behind
 * promo_codes.manage. A bounded collection, so the list page-paginates
 * (api-conventions); allowed query-builder parameters are filter[code]
 * (exact) and filter[active] (validity window against now); unknown
 * ones are rejected with 400 invalid_query_parameter. An unknown id is
 * the standard request.not_found, mirroring roles and ticket types.
 */
class PromoCodeAdminController
{
    public function index(Request $request): PaginatedDataCollection
    {
        $promoCodes = QueryBuilder::for(PromoCode::class)
            ->allowedFilters(
                AllowedFilter::exact('code'),
                AllowedFilter::callback('active', function (Builder $query, mixed $value): void {
                    $now = Date::now();

                    if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                        $query->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
                            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now));
                    } else {
                        $query->where(fn (Builder $q) => $q->where('valid_from', '>', $now)->orWhere('valid_to', '<', $now));
                    }
                }),
            )
            ->defaultSort('-created_at')
            ->allowedSorts('created_at')
            ->paginate()
            ->appends($request->query());

        return PromoCodeData::collect($promoCodes, PaginatedDataCollection::class);
    }

    public function store(UpsertPromoCodeData $data, CreatePromoCode $createPromoCode): JsonResponse
    {
        return response()->json($createPromoCode($data), 201);
    }

    public function show(string $promoCode): PromoCodeData
    {
        return PromoCodeData::fromModel($this->promoCodeOrFail($promoCode));
    }

    public function update(string $promoCode, UpsertPromoCodeData $data, UpdatePromoCode $updatePromoCode): JsonResponse
    {
        return response()->json($updatePromoCode($this->promoCodeOrFail($promoCode), $data));
    }

    private function promoCodeOrFail(string $promoCodeId): PromoCode
    {
        return PromoCode::query()->find($promoCodeId) ?? throw new NotFoundHttpException;
    }
}
