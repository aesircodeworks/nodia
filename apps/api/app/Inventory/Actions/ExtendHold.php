<?php

namespace App\Inventory\Actions;

use App\Inventory\Data\ExtendHoldData;
use App\Inventory\Data\HoldData;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Exceptions\HoldNotExtendableException;
use App\Inventory\Exceptions\HoldNotFoundException;
use App\Inventory\Models\Hold;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The internal extension Action (stage-06 plan, Slice 4, task breakdown
 * item 8; system-design 6.1: "UPDATE holds SET expires_at = :new WHERE id
 * = :id AND status = 'active' AND expires_at > now() AND :new >
 * expires_at"), a conditional UPDATE checked by affected-row count
 * (master plan test-first rule 2), never a read-then-write. No HTTP
 * surface exists in this stage; Stage 8a calls this in-process when
 * payment initiation opens an async window.
 */
final class ExtendHold
{
    public function __invoke(ExtendHoldData $data): HoldData
    {
        $hold = Hold::query()->with('items')->find($data->holdId) ?? throw HoldNotFoundException::forId($data->holdId);

        $affected = DB::table('holds')
            ->where('id', $data->holdId)
            ->where('status', HoldStatus::Active->value)
            ->where('expires_at', '>', Date::now())
            ->where('expires_at', '<', $data->expiresAt)
            ->update(['expires_at' => $data->expiresAt, 'updated_at' => Date::now()]);

        if ($affected === 0) {
            throw HoldNotExtendableException::forId($data->holdId);
        }

        return HoldData::fromModel($hold->fresh('items'));
    }
}
