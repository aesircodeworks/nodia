<?php

use App\Http\Middleware\CorrelationId;
use Symfony\Component\Finder\Finder;

// CorrelationId's microtime is duration measurement, not domain time, so
// freezing the clock must not affect it.
arch('app code stays off uncontrollable php time functions')
    ->expect('App')
    ->not->toUse(['time', 'date', 'mktime', 'microtime'])
    ->ignoring(CorrelationId::class);

test('app code obtains the current time only through the framework clock', function (): void {
    $constructions = '\bnew\s+\\\\?(?:Carbon\\\\)?(?:Carbon|CarbonImmutable|DateTime|DateTimeImmutable)\s*\(';
    $staticNow = '\b(?:Carbon|CarbonImmutable|DateTime|DateTimeImmutable)::(?:now|today|yesterday|tomorrow)\s*\(';
    $forbidden = "/{$constructions}|{$staticNow}/";

    $violations = [];

    $files = Finder::create()
        ->files()
        ->in(dirname(__DIR__, 2).'/app')
        ->name('*.php');

    foreach ($files as $file) {
        foreach (explode("\n", $file->getContents()) as $index => $line) {
            if (preg_match($forbidden, $line) === 1) {
                $violations[] = sprintf('%s:%d %s', $file->getRelativePathname(), $index + 1, trim($line));
            }
        }
    }

    expect($violations)->toBe([], sprintf(
        "App code must get the current time from the framework clock (the now() helper or the Date facade), never by constructing Carbon or DateTime instances or calling their static now family; freezeTime and travelTo cannot control those. Violations:\n%s",
        implode("\n", $violations),
    ));
});
