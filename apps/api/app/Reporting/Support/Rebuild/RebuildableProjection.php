<?php

namespace App\Reporting\Support\Rebuild;

use App\Support\Outbox\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Exposes the same increment-computation logic a Reporting projector
 * (App\Reporting\Jobs\*, each an OutboxSubscriber) uses to apply a live
 * delivery, so App\Reporting\Support\Rebuild\ReportingProjectionRebuilder
 * (stage-11 plan, task 13) can drive the identical resolution logic
 * without invoking the projector's own SQL write: computeIncrement()
 * never writes, keyFor()/fold()/rowFromModel() build the same comparable
 * plain-array row shape --verify folds in memory and compares against a
 * live row, with no projection table write either way.
 *
 * A single job class implements OutboxSubscriber (the live delivery
 * path) and RebuildableProjection (this one) together: both read the
 * same payload-resolution Actions, so there is exactly one place that
 * decides what an event means to this projection.
 */
interface RebuildableProjection
{
    /**
     * The reporting:rebuild {projection} argument value; equal to this
     * projection's own registered SubscriberRegistry name.
     */
    public function name(): string;

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string;

    /**
     * The projection row's natural key columns, excluding tenant_id, in
     * the order the table's own unique constraint declares them.
     *
     * @return list<string>
     */
    public function keyColumns(): array;

    /**
     * Resolves the event to this projection's commutative increment, or
     * null when the event carries no effect for this row (an unhandled
     * type, or a resolution miss the live projector itself would also
     * skip). Never writes.
     */
    public function computeIncrement(OutboxEvent $event): ?object;

    /**
     * The natural key an increment targets, as column name to value,
     * matching keyColumns().
     *
     * @return array<string, mixed>
     */
    public function keyFor(object $increment): array;

    /**
     * Folds one increment into the projection row accumulated so far
     * ($row is null the first time this key is seen), mirroring the
     * live projector's ON CONFLICT DO UPDATE arithmetic exactly (plain
     * addition for counters and money, LEAST/GREATEST for bounded
     * timestamps).
     *
     * @param  array<string, mixed>|null  $row
     * @return array<string, mixed>
     */
    public function fold(?array $row, object $increment): array;

    /**
     * The same value-column shape fold() produces, read from a live
     * model row, for --verify's drift comparison.
     *
     * @return array<string, mixed>
     */
    public function rowFromModel(Model $model): array;
}
