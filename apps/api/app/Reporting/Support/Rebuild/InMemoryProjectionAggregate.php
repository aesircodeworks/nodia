<?php

namespace App\Reporting\Support\Rebuild;

use App\Support\Outbox\Models\OutboxEvent;
use Illuminate\Support\Collection;

/**
 * Folds a sequence-ordered stream of outbox events into the same
 * commutative row state the live projector's SQL upsert would produce,
 * entirely in PHP memory: reporting:rebuild --verify never writes to the
 * projection table while computing drift (stage-11 plan, task 13:
 * "--verify replays into in-memory aggregates and reports drift against
 * the live table without writing").
 */
final class InMemoryProjectionAggregate
{
    /** @var array<string, array{key: array<string, mixed>, row: array<string, mixed>}> */
    private array $entries = [];

    public function __construct(private readonly RebuildableProjection $projection) {}

    public function apply(OutboxEvent $event): void
    {
        $increment = $this->projection->computeIncrement($event);

        if ($increment === null) {
            return;
        }

        $key = $this->projection->keyFor($increment);
        $token = self::token($key);

        $this->entries[$token] = [
            'key' => $key,
            'row' => $this->projection->fold($this->entries[$token]['row'] ?? null, $increment),
        ];
    }

    /**
     * @return Collection<string, array{key: array<string, mixed>, row: array<string, mixed>}>
     */
    public function entries(): Collection
    {
        return collect($this->entries);
    }

    /**
     * A stable string identity for a natural key, independent of column
     * declaration order, so the same logical key always maps to the same
     * token whether it was built from an increment or a live model row.
     *
     * @param  array<string, mixed>  $key
     */
    public static function token(array $key): string
    {
        ksort($key);

        return json_encode($key, JSON_THROW_ON_ERROR);
    }
}
