<?php

namespace App\Support\Testing;

/**
 * Field-level exemptions for the payload PII meta-test
 * (tests/Feature/Support/OutboxPayloadPiiTest.php; stage-12 plan, Slice 6,
 * task breakdown item 14). Every property on every registered outbox
 * payload Data class is scanned against name and type patterns that would
 * indicate a data subject's personal data (name, email, document number);
 * a property whose base identifier legitimately matches one of those
 * patterns without carrying personal data must be listed here with a
 * reason, or the scan fails the build.
 *
 * Keyed by "PayloadClass::$property" so a rename or a second colliding
 * field surfaces immediately rather than silently exempting the wrong
 * property.
 */
final class OutboxPayloadPiiExemptions
{
    /**
     * @return array<string, string>
     */
    public static function exempt(): array
    {
        return [
            'App\Tenancy\Events\TenantCreatedPayload::$name' => 'the tenant\'s own business/display name (system-design 8.1 scopes customer PII to customers.name/email only, not the tenant record)',
        ];
    }
}
