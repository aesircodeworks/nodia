<?php

namespace App\Payments\Support\Fixtures;

use App\Payments\Exceptions\GatewayFixtureNotCoveredException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;

/**
 * Replays recorded sandbox HTTP exchanges from tests/Fixtures/gateways/{slug}
 * (stage-08d plan, Slice 2). Every request the fixture set does not cover
 * fails loudly instead of falling through to a live call, so a stale or
 * incomplete fixture set cannot silently hit the network in CI.
 */
final class GatewayFixtureLoader
{
    public function __construct(private readonly string $fixturesRoot) {}

    /**
     * Fakes the HTTP client for the given gateway slug with every recorded
     * exchange under its fixture directory.
     *
     * @throws GatewayFixtureNotCoveredException when the slug has no fixture directory
     */
    public function fake(string $gateway): void
    {
        $exchanges = $this->load($gateway);

        // Per-signature replay cursors: when a fixture set records several
        // exchanges that all match the same request (a poll that returns
        // pending then active, a retry that fails then succeeds), repeated
        // identical requests advance through them in recorded order instead
        // of replaying the first one forever, which would strand an async
        // scenario on its initial response. Once the recorded sequence is
        // exhausted the last exchange keeps replaying, so a single-exchange
        // signature still answers every call the way it did before.
        $cursors = [];

        Http::fake(function (ClientRequest $request) use ($gateway, $exchanges, &$cursors) {
            $matching = [];

            foreach ($exchanges as $index => $exchange) {
                if ($this->matches($exchange['request'], $request)) {
                    $matching[] = $index;
                }
            }

            if ($matching === []) {
                throw GatewayFixtureNotCoveredException::forRequest($gateway, $request->method(), $request->url());
            }

            $key = implode(',', $matching);
            $cursor = $cursors[$key] ?? 0;
            $cursors[$key] = $cursor + 1;

            $exchange = $exchanges[$matching[min($cursor, count($matching) - 1)]];

            return Http::response(
                $exchange['response']['body'] ?? null,
                $exchange['response']['status'] ?? 200,
                $exchange['response']['headers'] ?? [],
            );
        });
    }

    /**
     * @return list<array{request: array<string, mixed>, response: array<string, mixed>}>
     */
    private function load(string $gateway): array
    {
        $directory = rtrim($this->fixturesRoot, '/').'/'.$gateway;

        if (! is_dir($directory)) {
            throw GatewayFixtureNotCoveredException::forGateway($gateway);
        }

        $exchanges = [];

        foreach (glob($directory.'/*.json') ?: [] as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            try {
                $exchange = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            $exchanges[] = $exchange;
        }

        return $exchanges;
    }

    /**
     * @param  array<string, mixed>  $matcher
     */
    private function matches(array $matcher, ClientRequest $request): bool
    {
        if (isset($matcher['method']) && strtoupper((string) $matcher['method']) !== $request->method()) {
            return false;
        }

        if (isset($matcher['url']) && ! Str::is($matcher['url'], $request->url())) {
            return false;
        }

        if (isset($matcher['body']) && $this->normalize($matcher['body']) !== $this->normalize($request->data())) {
            return false;
        }

        if (isset($matcher['headers']) && is_array($matcher['headers']) && ! $this->headersMatch($matcher['headers'], $request)) {
            return false;
        }

        return true;
    }

    /**
     * Every header the fixture requires must be present on the request with
     * a matching value. This is what lets the harness assert an adapter
     * transmits required gateway headers (an Idempotency-Key in
     * particular); an exchange recorded with a header only replays for a
     * request that actually carries it.
     *
     * @param  array<string, mixed>  $expected
     */
    private function headersMatch(array $expected, ClientRequest $request): bool
    {
        foreach ($expected as $name => $value) {
            if (! in_array((string) $value, $request->header((string) $name), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  mixed  $value
     */
    private function normalize($value): string
    {
        if (! is_array($value)) {
            return (string) $value;
        }

        ksort($value);

        return json_encode($value) ?: '';
    }
}
