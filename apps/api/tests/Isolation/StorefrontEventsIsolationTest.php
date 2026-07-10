<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/**
 * The storefront event surface runs entirely under the Host-resolved
 * nodia_app posture, so tenant A's host can never observe tenant B's
 * published events on list or detail, enforced by RLS end to end
 * (stage-05a plan, Slice 5; stage-02 plan, Slice 6).
 */
beforeEach(function (): void {
    TenantFixture::seed();

    $this->eventA = '019797f3-0000-7000-8000-0000000000a1';
    $this->eventB = '019797f3-0000-7000-8000-0000000000b1';

    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
        Event::factory()->create([
            'id' => $this->eventA,
            'tenant_id' => TenantFixture::TENANT_A,
            'status' => EventStatus::Published,
            'name' => ['en' => 'Tenant A Show'],
        ]);
    });

    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
        Event::factory()->create([
            'id' => $this->eventB,
            'tenant_id' => TenantFixture::TENANT_B,
            'status' => EventStatus::Published,
            'name' => ['en' => 'Tenant B Show'],
        ]);
    });
});

afterEach(function (): void {
    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, fn () => DB::table('events')->where('id', $this->eventA)->delete());
    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, fn () => DB::table('events')->where('id', $this->eventB)->delete());

    TenantFixture::clean();
});

it('shows tenant A\'s host only tenant A\'s published events', function () {
    $response = $this->getJson('http://'.TenantFixture::DOMAIN_A.'/v1/storefront/events')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Tenant A Show']);
});

it('returns request.not_found for tenant B\'s event id on tenant A\'s host', function () {
    $this->getJson('http://'.TenantFixture::DOMAIN_A.'/v1/storefront/events/'.$this->eventB)
        ->assertNotFound()
        ->assertJsonPath('code', 'request.not_found');
});

it('serves tenant B\'s own published event on tenant B\'s host', function () {
    $this->getJson('http://'.TenantFixture::DOMAIN_B.'/v1/storefront/events/'.$this->eventB)
        ->assertOk()
        ->assertJsonPath('name', 'Tenant B Show');
});
