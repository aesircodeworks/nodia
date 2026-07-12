<?php

namespace App\Payments\Support\Fixtures;

/**
 * Resolves the fixture files that belong to a single gateway scenario.
 *
 * Fixtures are written as `{scenario}-{index}.json` (see
 * RecordGatewayFixturesCommand). Selection is scoped to one scenario so
 * identical matchers recorded under different scenarios never bleed into
 * one replay sequence, and ordering is by the numeric index rather than
 * lexicographically so that `scenario-10` sorts after `scenario-2` instead
 * of between `scenario-1` and `scenario-2`.
 */
final class GatewayScenarioFixtures
{
    /**
     * @return list<string> absolute paths to the scenario's fixture files, in index order
     */
    public static function orderedPaths(string $directory, string $scenario): array
    {
        $pattern = '/^'.preg_quote($scenario, '/').'-(\d+)\.json$/';

        $matched = [];

        foreach (glob(rtrim($directory, '/').'/'.$scenario.'-*.json') ?: [] as $path) {
            if (preg_match($pattern, basename($path), $captured) === 1) {
                $matched[(int) $captured[1]] = $path;
            }
        }

        ksort($matched);

        return array_values($matched);
    }
}
