<?php

namespace App\Support\Media;

use Spatie\MediaLibrary\HasMedia;

/**
 * The accepted mime types, size ceiling, and queued conversions shared by
 * every image collection this app registers (stage-05c plan, Data model:
 * "Accepted mime types image/jpeg, image/png, image/webp" for both
 * Event's cover and gallery collections here and Tenant's logo collection
 * in a later task, "same accepted types"; "Conversions: thumb, card,
 * hero (fixed widths from config)... generated on queued jobs via
 * Horizon"). Factored out under App\Support\Media rather than duplicated
 * on each model, mirroring why the Media model and TenantPathGenerator
 * live here too: no single bounded context owns this rule, and Event
 * (this task) and Tenant (a later task) both need the identical
 * collections, mime allowlist, and conversion set rather than each
 * declaring its own copy that could drift.
 */
final class ImageMediaCollections
{
    /** @var list<string> */
    public const array ACCEPTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** @var list<string> */
    public const array CONVERSIONS = ['thumb', 'card', 'hero'];

    public static function maxUploadKilobytes(): int
    {
        return config()->integer('media.max_upload_kb');
    }

    public static function conversionWidth(string $conversionName): int
    {
        return config()->integer("media.conversions.{$conversionName}.width");
    }

    /**
     * Registers thumb, card, and hero on the given model, applying to
     * every media collection it defines (no ->performOnCollections() call,
     * which the package treats as "all collections", stage-05c plan Data
     * model: the same three conversions serve every collection). Each is
     * ->queued(), dispatching a Spatie\MediaLibrary\Conversions\Jobs\
     * PerformConversionsJob onto config('media-library.queue_name'), the
     * dedicated Horizon queue this task names.
     */
    public static function registerConversions(HasMedia $model): void
    {
        // Not chained: Conversion's ->width() is a magic call forwarded to
        // Spatie\Image\Drivers\ImageDriver via Conversion's @mixin/__call
        // pair, which PHPStan resolves to ImageDriver's own return type
        // rather than Conversion's, so a fluent ->width()->queued() chain
        // reports queued() as undefined on ImageDriver. Keeping $conversion
        // typed as Conversion throughout sidesteps the false positive.
        foreach (self::CONVERSIONS as $conversionName) {
            $conversion = $model->addMediaConversion($conversionName);
            $conversion->width(self::conversionWidth($conversionName));
            $conversion->queued();
        }
    }
}
