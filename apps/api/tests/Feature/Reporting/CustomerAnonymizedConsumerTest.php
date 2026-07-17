<?php

use App\Identity\Actions\AnonymizeCustomer;
use App\Identity\Models\Customer;
use App\Reporting\Jobs\ScrubReportingPii;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\ProjectionLock;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Domain events "Consumed" and task breakdown item 5: the
 * Reporting subscriber for Identity's CustomerAnonymized. Stage 11's
 * three read models (report_daily_sales, report_event_finance,
 * report_event_attendance) carry no PII: every column is an identifier,
 * a count, a money amount, a date, or a timestamp, so this consumer
 * lands as the registered no-op the plan's own "Consumed" section
 * names as the acceptable shape when a read model carries nothing to
 * scrub. Two things still get proven here regardless of that shape,
 * per the plan and task breakdown: the duplicate-delivery idempotence
 * test (identical requirement to Orders' own consumer, mirroring
 * tests/Feature/Orders/CustomerAnonymizedConsumerTest.php's structure)
 * and an assertion that the read models are in fact PII-free today, so
 * a future column that looks like PII fails this test loudly and
 * inherits the scrub obligation visibly rather than silently.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        foreach (['outbox_deliveries', 'outbox_events', 'customers'] as $table) {
            DB::table($table)->where('tenant_id', $this->tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete(),
    );
});

/**
 * Anonymizes a fresh customer through the real Action (Identity's own
 * producer) and returns the CustomerAnonymized outbox event id it
 * records, mirroring tests/Feature/Orders/CustomerAnonymizedConsumerTest.php's
 * own posture of driving the producer for real rather than hand-rolling
 * an outbox row.
 */
function anonymizedCustomerEventId(string $tenantId): string
{
    $customerId = app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): string {
        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);

        DB::transaction(function () use ($customer): void {
            app(AnonymizeCustomer::class)($customer, Str::uuid7()->toString());
        });

        return $customer->id;
    });

    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()
            ->where('type', 'CustomerAnonymized')
            ->where('aggregate_id', $customerId)
            ->value('id'),
    );
}

function runReportingScrubDelivery(string $outboxEventId): void
{
    (new ProcessOutboxDelivery($outboxEventId, ScrubReportingPii::NAME))->handle(
        app(TenantTransaction::class),
        app(SubscriberRegistry::class),
        app(OrderedConsumption::class),
        app(ProjectionLock::class),
    );
}

it('registers the subscriber for CustomerAnonymized', function (): void {
    expect(app(SubscriberRegistry::class)->namesFor('CustomerAnonymized'))->toContain(ScrubReportingPii::NAME);
});

it('has exactly one effect under duplicate delivery of the same event id', function (): void {
    $outboxEventId = anonymizedCustomerEventId($this->tenantId);

    runReportingScrubDelivery($outboxEventId);
    runReportingScrubDelivery($outboxEventId);

    $processed = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('outbox_deliveries')
            ->where('outbox_event_id', $outboxEventId)
            ->where('subscriber', ScrubReportingPii::NAME)
            ->where('status', 'processed')
            ->count(),
    );

    expect($processed)->toBe(1);
});

it('asserts the stage-11 read models carry no PII, so a future PII column inherits the scrub obligation visibly', function (): void {
    $piiPatterns = ['name', 'email', 'phone', 'address', 'document', 'dob', 'birth'];

    $tables = ['report_daily_sales', 'report_event_finance', 'report_event_attendance'];

    foreach ($tables as $table) {
        $columns = Schema::getColumnListing($table);

        foreach ($columns as $column) {
            foreach ($piiPatterns as $pattern) {
                expect(str_contains($column, $pattern))->toBeFalse(
                    "Column [{$table}.{$column}] looks like PII: App\\Reporting\\Jobs\\ScrubReportingPii must scrub it instead of staying a no-op.",
                );
            }
        }
    }
});
