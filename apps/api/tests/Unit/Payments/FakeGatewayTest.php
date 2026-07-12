<?php

use App\Payments\Enums\PaymentMethodConfirmation;
use App\Payments\Enums\PayoutStatus;
use App\Payments\Enums\SubmerchantStatus;
use App\Payments\Exceptions\GatewayUnavailableException;
use App\Payments\Exceptions\WebhookSignatureInvalidException;
use App\Payments\Exceptions\WebhookUnparseableException;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeGatewayScenarios;
use App\Payments\Gateways\GatewayPaymentOutcome;
use App\Payments\Gateways\GatewayPaymentRequest;
use App\Payments\Gateways\GatewayPayoutRecord;
use App\Payments\Gateways\GatewaySubmerchantResult;
use App\Payments\Gateways\NormalizedPaymentEvent;
use App\Payments\Gateways\SubmerchantRegistrationRequest;
use App\Payments\Gateways\WebhookKind;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 * Stage-08a plan, Slice 1: adapter conformance and FakeGateway scenario
 * controls. No endpoint or table exists yet; everything here is the
 * gateway seam the rest of the stage builds against.
 */

beforeEach(function (): void {
    $this->scenarios = new FakeGatewayScenarios;
    $this->gateway = new FakeGateway($this->scenarios);
});

function fakePaymentRequest(string $method, array $details = [], ?string $paymentId = null): GatewayPaymentRequest
{
    return new GatewayPaymentRequest(
        paymentId: $paymentId ?? (string) Str::uuid7(),
        orderId: (string) Str::uuid7(),
        method: $method,
        amount: Money::of(12500, 'BRL'),
        details: $details,
    );
}

it('exposes capability flags for methods, currencies, async confirmation, and splits', function (): void {
    $capabilities = $this->gateway->capabilities();

    expect($this->gateway->identifier())->toBe('fake')
        ->and($capabilities->currencies)->toContain('BRL')
        ->and($capabilities->asyncConfirmation)->toBeTrue()
        ->and($capabilities->splitSupport)->toBeFalse();

    $card = $capabilities->method('card');
    $pix = $capabilities->method('pix');
    $boleto = $capabilities->method('boleto');

    expect($card->confirmation)->toBe(PaymentMethodConfirmation::Sync)
        ->and($card->confirmationWindowMinutes)->toBeNull()
        ->and($pix->confirmation)->toBe(PaymentMethodConfirmation::Async)
        ->and($pix->confirmationWindowMinutes)->toBeGreaterThan(0)
        ->and($boleto->confirmation)->toBe(PaymentMethodConfirmation::Async)
        ->and($boleto->confirmationWindowMinutes)->toBeGreaterThan($pix->confirmationWindowMinutes);
});

it('approves a card payment synchronously with a deterministic fee and gateway reference', function (): void {
    $request = fakePaymentRequest('card', ['token' => 'tok_approve']);

    $result = $this->gateway->createPayment($request);

    expect($result->outcome)->toBe(GatewayPaymentOutcome::Approved)
        ->and($result->gatewayReference)->toBe('fake_'.$request->paymentId)
        ->and($result->fee)->not->toBeNull()
        ->and($result->fee->currency)->toBe('BRL')
        ->and($result->fee->amount)->toBe($this->gateway->feeFor(Money::of(12500, 'BRL'))->amount)
        ->and($result->nextAction->type)->toBe('none');

    $repeat = $this->gateway->createPayment($request);
    expect($repeat->fee->amount)->toBe($result->fee->amount);
});

it('declines a card payment synchronously with a normalized failure code', function (): void {
    $result = $this->gateway->createPayment(fakePaymentRequest('card', ['token' => 'tok_decline']));

    expect($result->outcome)->toBe(GatewayPaymentOutcome::Declined)
        ->and($result->failureCode)->toBe('card_declined')
        ->and($result->fee)->toBeNull();
});

it('returns a pending result with a display_code next action for async methods', function (): void {
    $request = fakePaymentRequest('pix');

    $result = $this->gateway->createPayment($request);

    expect($result->outcome)->toBe(GatewayPaymentOutcome::Pending)
        ->and($result->gatewayReference)->toBe('fake_'.$request->paymentId)
        ->and($result->nextAction->type)->toBe('display_code')
        ->and($result->nextAction->code)->not->toBeNull();
});

it('throws gateway unavailable when a transport failure is scripted', function (): void {
    $this->scenarios->failNextCreate();

    expect(fn () => $this->gateway->createPayment(fakePaymentRequest('card', ['token' => 'tok_approve'])))
        ->toThrow(GatewayUnavailableException::class);

    $result = $this->gateway->createPayment(fakePaymentRequest('card', ['token' => 'tok_approve']));
    expect($result->outcome)->toBe(GatewayPaymentOutcome::Approved);
});

it('emits confirmation webhooks whose signature the adapter verifies', function (): void {
    $delivery = $this->gateway->confirmationWebhook('fake_abc', Money::of(363, 'BRL'));

    $parsed = $this->gateway->parseWebhook($delivery->body, $delivery->headers);

    expect($parsed->gatewayEventId)->not->toBe('')
        ->and($parsed->payload['reference'])->toBe('fake_abc');
});

it('rejects a webhook with a tampered signature and persists nothing about it', function (): void {
    $delivery = $this->gateway->confirmationWebhook('fake_abc', Money::of(363, 'BRL'));

    expect(fn () => $this->gateway->parseWebhook($delivery->body, ['X-Fake-Signature' => 'bogus']))
        ->toThrow(WebhookSignatureInvalidException::class)
        ->and(fn () => $this->gateway->parseWebhook($delivery->body, []))
        ->toThrow(WebhookSignatureInvalidException::class);
});

it('rejects a validly signed body that carries no gateway event id', function (): void {
    $body = json_encode(['type' => 'payment.confirmed']);
    $headers = ['X-Fake-Signature' => hash_hmac('sha256', $body, config('payments.gateways.fake.webhook_secret'))];

    expect(fn () => $this->gateway->parseWebhook($body, $headers))
        ->toThrow(WebhookUnparseableException::class);
});

it('emits duplicate webhooks sharing one gateway event id when scripted', function (): void {
    $first = $this->gateway->confirmationWebhook('fake_abc', Money::of(363, 'BRL'), eventId: 'evt_dup');
    $second = $this->gateway->confirmationWebhook('fake_abc', Money::of(363, 'BRL'), eventId: 'evt_dup');

    expect($this->gateway->parseWebhook($first->body, $first->headers)->gatewayEventId)
        ->toBe($this->gateway->parseWebhook($second->body, $second->headers)->gatewayEventId);
});

it('normalizes confirmation and failure webhooks into payment events', function (): void {
    $confirmation = $this->gateway->confirmationWebhook('fake_abc', Money::of(363, 'BRL'));
    $failure = $this->gateway->failureWebhook('fake_def', 'card_declined');

    $confirmed = $this->gateway->normalizeWebhook($this->gateway->parseWebhook($confirmation->body, $confirmation->headers)->payload);
    $failed = $this->gateway->normalizeWebhook($this->gateway->parseWebhook($failure->body, $failure->headers)->payload);

    expect($confirmed)->toBeInstanceOf(NormalizedPaymentEvent::class)
        ->and($confirmed->kind)->toBe(WebhookKind::Confirmed)
        ->and($confirmed->gatewayReference)->toBe('fake_abc')
        ->and($confirmed->fee->equals(Money::of(363, 'BRL')))->toBeTrue()
        ->and($failed->kind)->toBe(WebhookKind::Failed)
        ->and($failed->gatewayReference)->toBe('fake_def')
        ->and($failed->failureCode)->toBe('card_declined')
        ->and($this->gateway->normalizeWebhook(['type' => 'payment.unknown']))->toBeNull();
});

it('refuses to normalize a confirmation fee that is not integer minor units with a valid currency', function (): void {
    $base = ['type' => 'payment.confirmed', 'reference' => 'fake_abc'];

    expect($this->gateway->normalizeWebhook($base + ['fee' => ['amount' => 3.63, 'currency' => 'BRL']]))->toBeNull()
        ->and($this->gateway->normalizeWebhook($base + ['fee' => ['amount' => '363', 'currency' => 'BRL']]))->toBeNull()
        ->and($this->gateway->normalizeWebhook($base + ['fee' => ['amount' => 363]]))->toBeNull()
        ->and($this->gateway->normalizeWebhook($base + ['fee' => ['amount' => 363, 'currency' => 'reais']]))->toBeNull()
        ->and($this->gateway->normalizeWebhook($base + ['fee' => ['amount' => -1, 'currency' => 'BRL']]))->toBeNull()
        ->and($this->gateway->normalizeWebhook($base))->toBeNull();
});

it('answers poller queries only from the scripted scenario store', function (): void {
    expect($this->gateway->queryPayment('fake_abc'))->toBeNull();

    $this->scenarios->scriptQueryResult('fake_abc', NormalizedPaymentEvent::confirmed('fake_abc', Money::of(363, 'BRL')));

    expect($this->gateway->queryPayment('fake_abc')->kind)->toBe(WebhookKind::Confirmed);
});

/*
 * Stage-08c plan, Slice 1: GatewayAdapter sub-merchant and payout
 * operations, exercised through FakeGateway scenario controls.
 */

function submerchantRequest(string $tenantId = 'tenant-1'): SubmerchantRegistrationRequest
{
    return new SubmerchantRegistrationRequest(
        tenantId: $tenantId,
        settlementCurrency: 'BRL',
        payoutSchedule: 'weekly',
    );
}

it('starts sub-merchant onboarding pending by default, with a deterministic reference and onboarding url', function (): void {
    $result = $this->gateway->createSubmerchant(submerchantRequest('tenant-1'));

    expect($result->status)->toBe(SubmerchantStatus::Pending)
        ->and($result->gatewayAccountReference)->toBe('fakesm_tenant-1')
        ->and($result->onboardingUrl)->toContain('fakesm_tenant-1')
        ->and($result->requirements)->toBe([]);
});

it('approves a sub-merchant immediately when the scenario is scripted', function (): void {
    $this->scenarios->scriptSubmerchantCreation(GatewaySubmerchantResult::active('fakesm_tenant-1'));

    $result = $this->gateway->createSubmerchant(submerchantRequest('tenant-1'));

    expect($result->status)->toBe(SubmerchantStatus::Active)
        ->and($result->gatewayAccountReference)->toBe('fakesm_tenant-1');
});

it('holds a sub-merchant under review when the scenario is scripted', function (): void {
    $this->scenarios->scriptSubmerchantCreation(GatewaySubmerchantResult::underReview('fakesm_tenant-1'));

    $result = $this->gateway->createSubmerchant(submerchantRequest('tenant-1'));

    expect($result->status)->toBe(SubmerchantStatus::UnderReview);
});

it('requires action on a sub-merchant with an outstanding requirements list when the scenario is scripted', function (): void {
    $this->scenarios->scriptSubmerchantCreation(
        GatewaySubmerchantResult::actionRequired('fakesm_tenant-1', ['proof_of_address', 'bank_statement']),
    );

    $result = $this->gateway->createSubmerchant(submerchantRequest('tenant-1'));

    expect($result->status)->toBe(SubmerchantStatus::ActionRequired)
        ->and($result->requirements)->toBe(['proof_of_address', 'bank_statement']);
});

it('rejects a sub-merchant when the scenario is scripted, and allows a retry to reset it to pending', function (): void {
    $this->scenarios->scriptSubmerchantCreation(GatewaySubmerchantResult::rejected('fakesm_tenant-1'));

    $rejected = $this->gateway->createSubmerchant(submerchantRequest('tenant-1'));
    expect($rejected->status)->toBe(SubmerchantStatus::Rejected);

    $this->scenarios->scriptSubmerchantStatus('fakesm_tenant-1', GatewaySubmerchantResult::pending('fakesm_tenant-1'));
    $retried = $this->gateway->fetchSubmerchantStatus('fakesm_tenant-1');

    expect($retried->status)->toBe(SubmerchantStatus::Pending);
});

it('fetches the scripted sub-merchant status, falling back to pending when nothing was scripted', function (): void {
    expect($this->gateway->fetchSubmerchantStatus('fakesm_unknown')->status)->toBe(SubmerchantStatus::Pending);

    $this->scenarios->scriptSubmerchantStatus('fakesm_tenant-1', GatewaySubmerchantResult::active('fakesm_tenant-1'));

    expect($this->gateway->fetchSubmerchantStatus('fakesm_tenant-1')->status)->toBe(SubmerchantStatus::Active);
});

it('emits sub-merchant status webhooks the adapter verifies, carrying the requirements list', function (): void {
    $delivery = $this->gateway->submerchantStatusWebhook('fakesm_tenant-1', SubmerchantStatus::ActionRequired, ['bank_statement']);

    $parsed = $this->gateway->parseWebhook($delivery->body, $delivery->headers);

    expect($parsed->payload['reference'])->toBe('fakesm_tenant-1')
        ->and($parsed->payload['status'])->toBe('action_required')
        ->and($parsed->payload['requirements'])->toBe(['bank_statement']);
});

it('emits duplicate sub-merchant webhooks sharing one gateway event id when scripted', function (): void {
    $first = $this->gateway->submerchantStatusWebhook('fakesm_tenant-1', SubmerchantStatus::Active, eventId: 'evt_sm_dup');
    $second = $this->gateway->submerchantStatusWebhook('fakesm_tenant-1', SubmerchantStatus::Active, eventId: 'evt_sm_dup');

    expect($this->gateway->parseWebhook($first->body, $first->headers)->gatewayEventId)
        ->toBe($this->gateway->parseWebhook($second->body, $second->headers)->gatewayEventId);
});

it('lists payouts only from the scripted scenario store, optionally filtered since an instant', function (): void {
    expect($this->gateway->listPayouts())->toBe([]);

    $old = new GatewayPayoutRecord('fake_po_1', Money::of(1000, 'BRL'), PayoutStatus::Paid, CarbonImmutable::parse('2026-01-01T00:00:00Z'));
    $recent = new GatewayPayoutRecord('fake_po_2', Money::of(2000, 'BRL'), PayoutStatus::Paid, CarbonImmutable::parse('2026-07-01T00:00:00Z'));
    $this->scenarios->scriptPayouts([$old, $recent]);

    expect($this->gateway->listPayouts())->toBe([$old, $recent])
        ->and($this->gateway->listPayouts(CarbonImmutable::parse('2026-06-01T00:00:00Z')))->toBe([$recent]);
});

it('emits a payout executed webhook the adapter verifies', function (): void {
    $executedAt = CarbonImmutable::parse('2026-07-12T10:00:00Z');
    $delivery = $this->gateway->payoutStatusWebhook('fake_po_1', PayoutStatus::Paid, $executedAt);

    $parsed = $this->gateway->parseWebhook($delivery->body, $delivery->headers);

    expect($parsed->payload['reference'])->toBe('fake_po_1')
        ->and($parsed->payload['status'])->toBe('paid')
        ->and($parsed->payload['executed_at'])->toBe($executedAt->toIso8601String());
});

it('emits a payout failed webhook the adapter verifies', function (): void {
    $delivery = $this->gateway->payoutStatusWebhook('fake_po_1', PayoutStatus::Failed);

    $parsed = $this->gateway->parseWebhook($delivery->body, $delivery->headers);

    expect($parsed->payload['reference'])->toBe('fake_po_1')
        ->and($parsed->payload['status'])->toBe('failed')
        ->and($parsed->payload['executed_at'])->toBeNull();
});

it('emits a payout created webhook carrying the amount', function (): void {
    $delivery = $this->gateway->payoutCreatedWebhook('fake_po_1', Money::of(1500, 'BRL'));

    $parsed = $this->gateway->parseWebhook($delivery->body, $delivery->headers);

    expect($parsed->payload['reference'])->toBe('fake_po_1')
        ->and($parsed->payload['status'])->toBe('pending')
        ->and($parsed->payload['amount'])->toBe(['amount' => 1500, 'currency' => 'BRL']);
});

it('emits duplicate payout webhooks sharing one gateway event id when scripted', function (): void {
    $first = $this->gateway->payoutStatusWebhook('fake_po_1', PayoutStatus::Paid, eventId: 'evt_po_dup');
    $second = $this->gateway->payoutStatusWebhook('fake_po_1', PayoutStatus::Paid, eventId: 'evt_po_dup');

    expect($this->gateway->parseWebhook($first->body, $first->headers)->gatewayEventId)
        ->toBe($this->gateway->parseWebhook($second->body, $second->headers)->gatewayEventId);
});
