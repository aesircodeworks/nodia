export type AcceptInvitationData = {
  token: string;
  password: string;
};
export type BrandingSettingsData = {
  primary_color: string | null;
  logo_url: string | null;
};
export type Capability =
  | 'roles.manage'
  | 'memberships.manage'
  | 'tenants.manage'
  | 'events.view'
  | 'events.manage'
  | 'events.publish'
  | 'orders.view'
  | 'orders.refund'
  | 'payouts.view'
  | 'checkin.scan';
export type CapabilityData = {
  name: string;
  is_financially_privileged: boolean;
};
export type CapabilityListData = {
  data: CapabilityData[];
};
export type ChangeMembershipRoleData = {
  role_id: string;
};
export type CheckResult = 'ok' | 'failed';
export type ConfirmMfaData = {
  code: string;
};
export type CreateRoleData = {
  name: string;
  capabilities: string[];
};
export type CreateTenantData = {
  name: string;
  default_locale: string;
  supported_locales: string[];
  branding_settings?: BrandingSettingsData;
  enabled_gateways?: string[];
  payout_schedule?: Record<string, unknown> | null;
};
export type CurrentUserData = {
  id: string;
  name: string;
  email: string;
  mfa_enabled: boolean;
  memberships: MembershipData[];
};
export type CursorPaginatedDataCollection<TKey, TValue> = CursorPaginator<TKey, TValue>;
export type CursorPaginator<TKey, TValue> = {
  data: TKey extends string ? Record<TKey, TValue> : TValue[];
  links: {
    url: string | null;
    label: string;
    active: boolean;
  }[];
  meta: {
    path: string;
    per_page: number;
    next_cursor: string | null;
    next_page_url: string | null;
    prev_cursor: string | null;
    prev_page_url: string | null;
  };
};
export type CursorPaginatorInterface<TKey, TValue> = CursorPaginator<TKey, TValue>;
export type DisableMfaData = {
  code: string;
};
export type DomainVerificationData = {
  domain: string;
};
export type DomainVerifiedPayload = {
  tenant_domain_id: string;
  tenant_id: string;
  domain: string;
};
export type ErrorCode =
  | 'request.not_found'
  | 'request.method_not_allowed'
  | 'request.validation_failed'
  | 'auth.unauthenticated'
  | 'auth.forbidden'
  | 'request.rate_limited'
  | 'server.internal_error'
  | 'health.degraded'
  | 'invalid_query_parameter'
  | 'tenant_not_found'
  | 'default_locale_not_supported'
  | 'tenant_domain_not_found'
  | 'domain_already_registered'
  | 'tenant_domain_is_primary'
  | 'unknown_host'
  | 'missing_tenant_header'
  | 'invalid_tenant_header'
  | 'tenant_access_denied'
  | 'unknown_domain'
  | 'invalid_credentials'
  | 'invalid_refresh_token'
  | 'refresh_token_reused'
  | 'missing_capability'
  | 'role_not_editable'
  | 'role_in_use'
  | 'role_name_taken'
  | 'unknown_capability'
  | 'membership_exists'
  | 'last_owner_removal'
  | 'invitation_token_invalid'
  | 'invitation_token_expired'
  | 'mfa_required'
  | 'mfa_code_invalid'
  | 'mfa_already_enrolled'
  | 'mfa_not_enrolled'
  | 'mfa_enforced_for_role'
  | 'mfa_enforcement_required';
export type HealthChecksData = {
  database: CheckResult;
  redis: CheckResult;
  storage: CheckResult;
};
export type HealthDegradedProblemData = {
  checks: HealthChecksData;
  checked_at: string;
  type: string;
  title: string;
  status: number;
  detail: string;
  code: string;
  correlation_id?: string;
};
export type HealthReportData = {
  status: HealthStatus;
  checks: HealthChecksData;
  checked_at: string;
};
export type HealthStatus = 'ok';
export type InviteUserData = {
  email: string;
  name: string;
  role_id: string;
};
export type LengthAwarePaginator<TKey, TValue> = {
  data: TKey extends string ? Record<TKey, TValue> : TValue[];
  links: {
    url: string | null;
    label: string;
    active: boolean;
  }[];
  meta: {
    total: number;
    current_page: number;
    first_page_url: string;
    from: number | null;
    last_page: number;
    last_page_url: string;
    next_page_url: string | null;
    path: string;
    per_page: number;
    prev_page_url: string | null;
    to: number | null;
  };
};
export type LengthAwarePaginatorInterface<TKey, TValue> = LengthAwarePaginator<TKey, TValue>;
export type MembershipData = {
  id: string;
  user_id: string;
  user_name: string;
  user_email: string;
  tenant_id: string;
  role_id: string;
  role_name: string;
  scope: string;
};
export type MembershipScope = 'tenant' | 'platform';
export type MfaEnrollmentData = {
  secret: string;
  otpauth_uri: string;
};
export type MfaRecoveryCodesData = {
  recovery_codes: string[];
};
export type Money = {
  amount: number;
  currency: string;
};
export type PaginatedDataCollection<TKey, TValue> = LengthAwarePaginator<TKey, TValue>;
export type ProblemData = {
  type: string;
  title: string;
  status: number;
  detail: string;
  code: string;
  correlation_id?: string;
};
export type RefreshTokenRequestData = {
  refresh_token: string;
};
export type RegisterTenantDomainData = {
  domain: string;
  is_primary?: boolean;
};
export type RoleData = {
  id: string;
  tenant_id: string | null;
  name: string;
  capabilities: string[];
  is_template: boolean;
};
export type StaffTokenRequestData = {
  email: string;
  password: string;
  mfa_code: string | null;
};
export type TenantCreatedPayload = {
  tenant_id: string;
  name: string;
  default_locale: string;
};
export type TenantData = {
  id: string;
  name: string;
  branding_settings: BrandingSettingsData;
  default_locale: string;
  supported_locales: string[];
  enabled_gateways: string[];
  payout_schedule: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
};
export type TenantDomainData = {
  id: string;
  tenant_id: string;
  domain: string;
  is_primary: boolean;
  created_at: string;
  updated_at: string;
};
export type TokenPairData = {
  access_token: string;
  refresh_token: string;
  token_type: string;
  expires_in: number;
};
export type UpdateRoleData = {
  name?: string;
  capabilities?: string[];
};
export type UpdateTenantData = {
  name?: string;
  branding_settings?: BrandingSettingsData;
  default_locale?: string;
  supported_locales?: string[];
  enabled_gateways?: string[];
  payout_schedule?: Record<string, unknown> | null;
};
export type UpdateTenantDomainData = {
  is_primary?: boolean;
};
export type ValidationProblemData = {
  errors: Record<string, string[]>;
  type: string;
  title: string;
  status: number;
  detail: string;
  code: string;
  correlation_id?: string;
};
