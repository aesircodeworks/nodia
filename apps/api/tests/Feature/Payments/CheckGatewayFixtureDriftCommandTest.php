<?php

it('fails when no recorder is registered for the gateway slug', function (): void {
    $this->artisan('gateway:check-fixture-drift', ['slug' => 'no-such-gateway', 'scenario' => 'happy-path'])
        ->assertFailed();
});
