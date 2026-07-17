<?php

use App\Payments\Support\Fixtures\GatewayFixtureSanitizer;

it('scans a fixture directory and reports no violations for sanitized fixtures', function (): void {
    $sanitizer = new GatewayFixtureSanitizer;

    $violations = $sanitizer->scan(base_path('tests/Fixtures/gateways'));

    expect($violations)->toBeArray()->toBeEmpty();
});

it('flags a bearer token in a fixture file', function (): void {
    $dir = sys_get_temp_dir().'/nodia-fixture-guard-'.uniqid();
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/leaky.json', json_encode([
        'request' => ['headers' => ['Authorization' => 'Bearer sk_live_abc123def456ghi789']],
    ]));

    $violations = (new GatewayFixtureSanitizer)->scan($dir);

    expect($violations)->not->toBeEmpty()
        ->and(collect($violations)->pluck('pattern'))->toContain('bearer_token');
});

it('flags a known-format credential in a fixture file', function (): void {
    $dir = sys_get_temp_dir().'/nodia-fixture-guard-'.uniqid();
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/leaky.json', json_encode([
        'response' => ['body' => ['secret' => 'sk_live_51H8xyzABCDEFGHIJKLMNOP']],
    ]));

    $violations = (new GatewayFixtureSanitizer)->scan($dir);

    expect(collect($violations)->pluck('pattern'))->toContain('known_credential');
});

it('flags a pan-like digit run in a fixture file', function (): void {
    $dir = sys_get_temp_dir().'/nodia-fixture-guard-'.uniqid();
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/leaky.json', json_encode([
        'response' => ['body' => ['card' => '4242424242424242']],
    ]));

    $violations = (new GatewayFixtureSanitizer)->scan($dir);

    expect(collect($violations)->pluck('pattern'))->toContain('pan_like_digit_run');
});

it('redacts known secret shapes in an exchange before it is written', function (): void {
    $sanitizer = new GatewayFixtureSanitizer;

    $redacted = $sanitizer->redact([
        'request' => ['headers' => ['Authorization' => 'Bearer sk_live_abc123def456ghi789']],
        'response' => ['body' => ['card' => '4242424242424242']],
    ]);

    $violations = (new GatewayFixtureSanitizer)->scan(sanitizerTmpDirFor($redacted));

    expect($violations)->toBeEmpty();
});

function sanitizerTmpDirFor(array $exchange): string
{
    $dir = sys_get_temp_dir().'/nodia-fixture-guard-'.uniqid();
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/redacted.json', json_encode($exchange));

    return $dir;
}
