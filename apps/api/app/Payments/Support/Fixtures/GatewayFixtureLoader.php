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

        Http::fake(function (ClientRequest $request) use ($gateway, $exchanges) {
            foreach ($exchanges as $exchange) {
                if ($this->matches($exchange['request'], $request)) {
                    return Http::response(
                        $exchange['response']['body'] ?? null,
                        $exchange['response']['status'] ?? 200,
                        $exchange['response']['headers'] ?? [],
                    );
                }
            }

            throw GatewayFixtureNotCoveredException::forRequest($gateway, $request->method(), $request->url());
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
