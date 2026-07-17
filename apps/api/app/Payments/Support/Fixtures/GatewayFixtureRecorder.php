<?php

namespace App\Payments\Support\Fixtures;

/**
 * Hits a gateway's live sandbox with credentials from the environment and
 * returns the raw request/response exchanges for a named scenario
 * (stage-08d plan, Slice 2). Bound per gateway slug in
 * config('payments.fixture_recorders'); none are bound until the launch
 * gateway ADR lands and a real adapter exists to record against.
 */
interface GatewayFixtureRecorder
{
    /**
     * @return list<array{request: array<string, mixed>, response: array<string, mixed>}>
     */
    public function record(string $scenario): array;
}
