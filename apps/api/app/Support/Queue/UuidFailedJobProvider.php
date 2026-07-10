<?php

namespace App\Support\Queue;

use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;

/**
 * Laravel's stock database-uuids failer orders by a bigint `id` column
 * that data-conventions forbids. Nodia stores the job uuid as the
 * primary key, so listing orders by failed_at instead and still exposes
 * the uuid as `id` to Horizon and artisan queue:failed.
 */
final class UuidFailedJobProvider extends DatabaseUuidFailedJobProvider
{
    /**
     * @param  string|null  $queue
     * @return array<int, string>
     */
    public function ids($queue = null)
    {
        return $this->getTable()
            ->when(! is_null($queue), fn ($query) => $query->where('queue', $queue))
            ->orderByDesc('failed_at')
            ->pluck('uuid')
            ->all();
    }

    /**
     * @return array<int, object>
     */
    public function all()
    {
        return $this->getTable()->orderByDesc('failed_at')->get()->map(function ($record) {
            $record->id = $record->uuid;
            unset($record->uuid);

            return $record;
        })->all();
    }
}
