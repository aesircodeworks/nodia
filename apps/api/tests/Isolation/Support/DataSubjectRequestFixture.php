<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Identity\Models\DataSubjectRequest;
use App\Models\User;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on CustomerFixture with one open (pending) data subject request
 * per tenant (stage-12 plan, task breakdown item 1: "Isolation for the
 * new table"). The requesting staff member is created inline under
 * nodia_platform, mirroring CheckInFixture's own posture for users
 * (data_subject_requests.requested_by_user_id carries no tenant_id).
 * data_subject_requests carries no self-read or platform-write extras
 * (standard Rls::applyTenantPolicies posture, stage-12 plan Data model),
 * so this fixture mirrors CustomerFixture's own shape.
 */
final class DataSubjectRequestFixture
{
    public const REQUEST_A = '019797f7-0000-7000-8000-0000000000d1';

    public const REQUEST_B = '019797f7-0000-7000-8000-0000000000d2';

    public const USER_A = '019797f7-0000-7000-8000-0000000000e1';

    public const USER_B = '019797f7-0000-7000-8000-0000000000e2';

    public static function seed(): void
    {
        CustomerFixture::seed();

        actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
            User::factory()->create(['id' => self::USER_A, 'email' => 'dsr-a@data-subject-request-fixture.example']);
            User::factory()->create(['id' => self::USER_B, 'email' => 'dsr-b@data-subject-request-fixture.example']);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DataSubjectRequest::factory()->create([
                'id' => self::REQUEST_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'customer_id' => CustomerFixture::CUSTOMER_A,
                'requested_by_user_id' => self::USER_A,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DataSubjectRequest::factory()->create([
                'id' => self::REQUEST_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'customer_id' => CustomerFixture::CUSTOMER_B,
                'requested_by_user_id' => self::USER_B,
            ]);
        });
    }

    public static function clean(): void
    {
        // Deleted under nodia_app with each row's own tenant asserted:
        // data_subject_requests has no platform write policy, so
        // nodia_platform alone could not see past its tenant_isolation
        // policy either.
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('data_subject_requests')->where('id', self::REQUEST_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('data_subject_requests')->where('id', self::REQUEST_B)->delete();
        });

        actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
            DB::table('users')->whereIn('id', [self::USER_A, self::USER_B])->delete();
        });

        CustomerFixture::clean();
    }
}
