<?php

namespace App\Payments\Support\Fixtures;

/**
 * Replays a recorded scenario against a gateway's live sandbox (via its
 * bound GatewayFixtureRecorder) and diffs the actual exchanges against the
 * ones committed under tests/Fixtures/gateways/{slug} (stage-08d plan,
 * Slice 8 detector). Never run in CI: this makes real network calls
 * through the recorder, so it is only ever invoked manually or on a
 * scheduled cadence outside the per-PR pipeline.
 */
final class GatewayFixtureDriftDetector
{
    public function __construct(private readonly string $fixturesRoot) {}

    /**
     * @return list<array{gateway: string, scenario: string, index: int, reason: string, recorded: mixed, actual: mixed}>
     */
    public function detect(string $gateway, string $scenario, GatewayFixtureRecorder $recorder): array
    {
        $recordedExchanges = $this->loadRecordedExchanges($gateway, $scenario);
        $actualExchanges = $recorder->record($scenario);

        if ($recordedExchanges === []) {
            return [[
                'gateway' => $gateway,
                'scenario' => $scenario,
                'index' => 0,
                'reason' => 'no recorded fixture exists for this scenario',
                'recorded' => null,
                'actual' => $actualExchanges,
            ]];
        }

        if (count($recordedExchanges) !== count($actualExchanges)) {
            return [[
                'gateway' => $gateway,
                'scenario' => $scenario,
                'index' => 0,
                'reason' => sprintf(
                    'recorded %d exchange(s) but the live sandbox returned %d',
                    count($recordedExchanges),
                    count($actualExchanges),
                ),
                'recorded' => $recordedExchanges,
                'actual' => $actualExchanges,
            ]];
        }

        $drift = [];

        foreach ($recordedExchanges as $index => $recordedExchange) {
            $actualExchange = $actualExchanges[$index];

            if ($this->normalize($recordedExchange) !== $this->normalize($actualExchange)) {
                $drift[] = [
                    'gateway' => $gateway,
                    'scenario' => $scenario,
                    'index' => $index,
                    'reason' => 'recorded exchange does not match the live sandbox response',
                    'recorded' => $recordedExchange,
                    'actual' => $actualExchange,
                ];
            }
        }

        return $drift;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadRecordedExchanges(string $gateway, string $scenario): array
    {
        $directory = rtrim($this->fixturesRoot, '/').'/'.$gateway;

        if (! is_dir($directory)) {
            return [];
        }

        $files = glob($directory.'/'.$scenario.'*.json') ?: [];
        sort($files);

        $exchanges = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            $decoded = json_decode($contents, true);

            if (is_array($decoded)) {
                $exchanges[] = $decoded;
            }
        }

        return $exchanges;
    }

    /**
     * @param  array<string, mixed>  $exchange
     */
    private function normalize(array $exchange): string
    {
        $this->ksortRecursive($exchange);

        return json_encode($exchange) ?: '';
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function ksortRecursive(array &$value): void
    {
        ksort($value);

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->ksortRecursive($item);
            }
        }
    }
}
