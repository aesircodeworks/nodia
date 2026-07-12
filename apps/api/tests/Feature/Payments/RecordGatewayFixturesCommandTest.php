<?php

it('fails immediately without recording anything when CI is set', function (): void {
    config(['payments.ci' => true]);

    $this->artisan('gateway:record-fixtures', ['slug' => 'examplegw', 'scenario' => 'happy-path'])
        ->assertFailed();
});

it('fails when no recorder is registered for the gateway slug', function (): void {
    config(['payments.ci' => false]);

    $this->artisan('gateway:record-fixtures', ['slug' => 'no-such-gateway', 'scenario' => 'happy-path'])
        ->assertFailed();
});
