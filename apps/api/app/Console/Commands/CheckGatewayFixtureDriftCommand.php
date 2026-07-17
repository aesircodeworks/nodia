<?php

namespace App\Console\Commands;

use App\Payments\Support\Fixtures\GatewayFixtureDriftDetector;
use App\Payments\Support\Fixtures\GatewayFixtureRecorder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;

/**
 * Replays a recorded scenario against a gateway's live sandbox and diffs
 * the actual response against the committed fixture (stage-08d plan,
 * Slice 8 detector). This is the manually triggered sandbox verification
 * suite entry point: it makes real network calls through the gateway's
 * bound GatewayFixtureRecorder, so it never runs in CI's per-PR pipeline,
 * only on demand or on a scheduled cadence outside it.
 */
class CheckGatewayFixtureDriftCommand extends Command
{
    protected $signature = 'gateway:check-fixture-drift {slug : The gateway adapter slug} {scenario : The named scenario to verify}';

    protected $description = 'Replay a recorded scenario against the live sandbox and report drift from the committed fixture (manual, never run in CI)';

    public function handle(Container $container): int
    {
        $slug = (string) $this->argument('slug');
        $scenario = (string) $this->argument('scenario');

        $detector = new GatewayFixtureDriftDetector(base_path('tests/Fixtures/gateways'));

        $recorderClass = config("payments.fixture_recorders.{$slug}");

        if (! is_string($recorderClass) || ! is_a($recorderClass, GatewayFixtureRecorder::class, true)) {
            $this->error("No fixture recorder is registered for gateway [{$slug}]. Bind one in config('payments.fixture_recorders').");

            return self::FAILURE;
        }

        /** @var GatewayFixtureRecorder $recorder */
        $recorder = $container->make($recorderClass);

        $drift = $detector->detect($slug, $scenario, $recorder);

        if ($drift === []) {
            $this->info("No drift detected for [{$slug}] scenario [{$scenario}].");

            return self::SUCCESS;
        }

        $this->error(sprintf('Drift detected for [%s] scenario [%s]: %d exchange(s) diverged.', $slug, $scenario, count($drift)));

        foreach ($drift as $entry) {
            $this->line("  - exchange #{$entry['index']}: {$entry['reason']}");
        }

        return self::FAILURE;
    }
}
