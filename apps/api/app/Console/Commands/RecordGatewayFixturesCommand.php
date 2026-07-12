<?php

namespace App\Console\Commands;

use App\Payments\Support\Fixtures\GatewayFixtureRecorder;
use App\Payments\Support\Fixtures\GatewayFixtureSanitizer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;

/**
 * Records a fresh set of fixtures for a gateway scenario against its live
 * sandbox (stage-08d plan, Slice 2). Recording is a manual, documented
 * act: this command refuses to run in CI, and it sanitizes every
 * exchange before writing it so the sanitizer guard test passes on
 * freshly recorded fixtures too.
 */
class RecordGatewayFixturesCommand extends Command
{
    protected $signature = 'gateway:record-fixtures {slug : The gateway adapter slug} {scenario : The named scenario to record}';

    protected $description = 'Record a sanitized fixture set for a gateway scenario against its live sandbox (manual, never run in CI)';

    public function handle(Container $container, GatewayFixtureSanitizer $sanitizer): int
    {
        if (config('payments.ci')) {
            $this->error('Refusing to record gateway fixtures while CI is set. Recording is a manual, local act.');

            return self::FAILURE;
        }

        $slug = (string) $this->argument('slug');
        $scenario = (string) $this->argument('scenario');

        $recorderClass = config("payments.fixture_recorders.{$slug}");

        if (! is_string($recorderClass) || ! is_a($recorderClass, GatewayFixtureRecorder::class, true)) {
            $this->error("No fixture recorder is registered for gateway [{$slug}]. Bind one in config('payments.fixture_recorders').");

            return self::FAILURE;
        }

        /** @var GatewayFixtureRecorder $recorder */
        $recorder = $container->make($recorderClass);

        $exchanges = array_map(
            fn (array $exchange): array => $sanitizer->redact($exchange),
            $recorder->record($scenario),
        );

        $directory = base_path("tests/Fixtures/gateways/{$slug}");

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        // Re-recording is a full replace of the scenario. Clearing the old
        // set first stops a shorter recording from leaving stale
        // higher-index files behind that the loader and drift detector would
        // still consume as part of the scenario.
        foreach (glob("{$directory}/{$scenario}-*.json") ?: [] as $stale) {
            unlink($stale);
        }

        foreach ($exchanges as $index => $exchange) {
            $path = "{$directory}/{$scenario}-{$index}.json";
            file_put_contents($path, json_encode($exchange, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
            $this->info("Wrote {$path}");
        }

        return self::SUCCESS;
    }
}
