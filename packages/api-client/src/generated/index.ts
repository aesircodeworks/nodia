export type AcceptInvitationData = {
  token: string;
  password: string;
};
export type AsyncPaymentPolicyData = {
  slow_methods_enabled: boolean;
  low_inventory_cutoff: number | null;
};
export type BatchResultData = {
  results: ScanOutcomeData[];
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
  | 'payouts.manage'
  | 'ledger.view'
  | 'checkin.scan'
  | 'seat_maps.manage'
  | 'events.manage_seating'
  | 'orders.resend_tickets'
  | 'promo_codes.manage'
  | 'customers.view'
  | 'checkin.manage';
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
export type CheckEventAssignmentData = {
  user_id: string;
  event_id: string;
  capabilities: string[];
};
export type CheckInResult = 'accepted' | 'duplicate';
export type CheckInResultData = {
  check_in_id: string;
  ticket_id: string;
  event_id: string;
  result: CheckInResult;
  scanned_at: string;
  synced_at: string;
};
export type CheckPromoCodeData = {
  code: string;
  hold_id: string;
};
export type CheckResult = 'ok' | 'failed';
export type ClaimRequestData = {
  email: string;
};
export type CommitHoldData = {
  holdId: string;
};
export type ConfirmClaimData = {
  token: string;
  password: string;
};
export type ConfirmMfaData = {
  code: string;
};
export type ConfirmPasswordResetData = {
  token: string;
  password: string;
};
export type CreateEventData = {
  name: Record<string, string>;
  description: Record<string, string>;
  venue_id: string | null;
  is_virtual: boolean;
  virtual_event_url: string | null;
  start_at: string;
  end_at: string;
  timezone: string;
  async_payment_policy?: AsyncPaymentPolicyData;
};
export type CreateHoldData = {
  event_id: string;
  items: HoldItemInputData[];
  seat_ids?: string[];
};
export type CreateOrderData = {
  hold_id: string;
  attendee_names?: Record<string, string[]>;
  promo_code?: string;
};
export type CreateRefundData = {
  amount?: Money | null;
  reason?: string | null;
  ticket_ids?: string[] | null;
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
export type CreateTicketTypeData = {
  name: string;
  price: Money;
  sales_start: string | null;
  sales_end: string | null;
  requires_seat?: boolean;
  quantity?: number;
};
export type CreateVenueData = {
  name: string;
  address: string;
  city: string;
  country: string;
  capacity: number;
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
export type CustomerData = {
  id: string;
  email: string;
  name: string;
  locale: string;
  is_claimed: boolean;
};
export type CustomerSummaryData = {
  id: string;
  email: string;
  name: string | null;
  created_at: string;
};
export type CustomerTokenRequestData = {
  email: string;
  password: string;
};
export type DisableMfaData = {
  code: string;
};
export type DomainVerificationData = {
  domain: string;
};
export type ErrorCode =
  | 'request.not_found'
  | 'request.method_not_allowed'
  | 'request.validation_failed'
  | 'auth.unauthenticated'
  | 'auth.forbidden'
  | 'request.rate_limited'
  | 'payload_too_large'
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
  | 'mfa_enforcement_required'
  | 'tenant_mismatch'
  | 'customer_email_taken'
  | 'customer_already_claimed'
  | 'claim_token_invalid'
  | 'claim_token_expired'
  | 'reset_token_invalid'
  | 'reset_token_expired'
  | 'catalog.event_immutable'
  | 'catalog.currency_mismatch'
  | 'catalog.event_not_publishable'
  | 'catalog.event_not_cancelable'
  | 'catalog.seat_map_duplicate_seats'
  | 'catalog.seat_map_name_taken'
  | 'catalog.seat_map_conflict'
  | 'catalog.seat_map_venue_mismatch'
  | 'catalog.seat_map_virtual_event'
  | 'catalog.seat_map_in_use'
  | 'catalog.seat_map_required'
  | 'insufficient_inventory'
  | 'event_not_found'
  | 'ticket_type_not_in_event'
  | 'sales_window_closed'
  | 'hold_not_found'
  | 'hold_not_releasable'
  | 'hold_not_extendable'
  | 'hold_not_committable'
  | 'seat_selection_invalid'
  | 'seat_unavailable'
  | 'event_not_seated'
  | 'seat_not_modifiable'
  | 'checkout.hold_expired'
  | 'hold_already_converted'
  | 'order_not_found'
  | 'order_not_cancelable'
  | 'order_not_paid'
  | 'invalid_order_transition'
  | 'promo_code_invalid'
  | 'promo_code_not_active'
  | 'promo_code_exhausted'
  | 'promo_code_currency_mismatch'
  | 'promo_code_immutable_field'
  | 'idempotency_key_missing'
  | 'idempotency_key_reuse_mismatch'
  | 'order_not_payable'
  | 'payment_method_not_available'
  | 'payment_declined'
  | 'gateway_unavailable'
  | 'webhook_signature_invalid'
  | 'webhook_unparseable'
  | 'refund_payment_not_found'
  | 'refund_not_found'
  | 'payment_not_refundable'
  | 'refund_amount_exceeds_refundable'
  | 'refund_currency_mismatch'
  | 'refund_tickets_not_in_order'
  | 'gateway_unknown'
  | 'gateway_not_enabled'
  | 'gateway_not_configured'
  | 'submerchant_already_onboarded'
  | 'submerchant_not_active'
  | 'checkin_not_assigned'
  | 'qr_signature_invalid'
  | 'qr_key_revoked'
  | 'ticket_rotation_stale'
  | 'scanned_at_in_future'
  | 'ticket_not_found'
  | 'ticket_canceled'
  | 'ticket_refunded'
  | 'ticket_already_checked_in'
  | 'batch_too_large';
export type EventAssignmentData = {
  authorized: boolean;
};
export type EventAvailabilityData = {
  event_id: string;
  ticket_types: TicketTypeAvailabilityData[];
};
export type EventData = {
  id: string;
  tenant_id: string;
  venue_id: string | null;
  seat_map_id: string | null;
  venue?: VenueData | null;
  status: string;
  name: Record<string, string>;
  description: Record<string, string>;
  start_at: string;
  end_at: string;
  timezone: string;
  is_virtual: boolean;
  virtual_event_url: string | null;
  async_payment_policy: AsyncPaymentPolicyData;
  created_at: string;
  updated_at: string;
  ticket_types?: TicketTypeData[];
};
export type EventMediaListData = {
  collection: string | null;
};
export type EventMediaUploadData = {
  file: File;
  collection: string;
};
export type EventSeatBatchData = {
  seats: EventSeatData[];
};
export type EventSeatData = {
  event_seat_id: string;
  event_id: string;
  seat_id: string;
  ticket_type_id: string | null;
  status: string;
  hold_id: string | null;
};
export type EventSeatStatus = 'available' | 'held' | 'sold' | 'blocked';
export type EventStatus = 'draft' | 'published' | 'canceled';
export type ExtendHoldData = {
  holdId: string;
  expiresAt: string;
};
export type GatewayPaymentOutcome = 'approved' | 'declined' | 'pending';
export type GatewayWebhookStatus = 'received' | 'processed' | 'ignored';
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
export type HoldData = {
  id: string;
  event_id: string;
  status: string;
  expires_at: string;
  items: HoldItemData[];
  seat_ids: string[];
};
export type HoldItemData = {
  ticket_type_id: string;
  quantity: number;
};
export type HoldItemInputData = {
  ticket_type_id: string;
  quantity: number;
};
export type HoldStatus = 'active' | 'released' | 'expired' | 'committed';
export type InitiatePaymentData = {
  method: string;
  details?: Record<string, unknown>;
};
export type InviteUserData = {
  email: string;
  name: string;
  role_id: string;
};
export type LedgerAccount =
  'gateway_receivable' | 'gateway_fees' | 'platform_commission' | 'tenant_net';
export type LedgerBalanceData = {
  currency: string;
  account: LedgerAccount;
  balance: Money;
};
export type LedgerDirection = 'debit' | 'credit';
export type LedgerEntryData = {
  id: string;
  account: LedgerAccount;
  direction: LedgerDirection;
  amount: Money;
  reference_type: string;
  reference_id: string;
  created_at: string;
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
export type ManifestEntryData = {
  ticket_id: string;
  status: string;
  rotation_counter: number;
  checked_in_at: string | null;
};
export type MediaConversionsData = {
  thumb: string | null;
  card: string | null;
  hero: string | null;
};
export type MediaData = {
  id: string;
  collection: string;
  file_name: string;
  mime_type: string;
  size: number;
  url: string;
  conversions: MediaConversionsData;
  alt_text: string | null;
  order: number;
  created_at: string;
};
export type MediaImageData = {
  id: string;
  url: string;
  conversions: MediaConversionsData;
  alt_text: string | null;
};
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
export type NextActionData = {
  type: string;
  redirect_url: string | null;
  code: string | null;
};
export type OfflineScanData = {
  client_scan_id: string;
  qr_payload: string;
  scanned_at: string;
};
export type OrderData = {
  id: string;
  status: string;
  event_id: string;
  items: OrderItemData[];
  subtotal: Money;
  discount: Money;
  fees: Money;
  total: Money;
  promo_code: string | null;
  created_at: string;
};
export type OrderDetailData = {
  id: string;
  status: string;
  event_id: string;
  items: OrderItemData[];
  subtotal: Money;
  discount: Money;
  fees: Money;
  total: Money;
  promo_code: string | null;
  created_at: string;
  tickets: TicketData[];
  customer: CustomerSummaryData | null;
};
export type OrderItemData = {
  ticket_type_id: string;
  quantity: number;
  unit_price: Money;
  attendee_names: string[] | null;
};
export type OrderStatus =
  | 'pending'
  | 'awaiting_payment'
  | 'paid'
  | 'expired'
  | 'failed'
  | 'canceled'
  | 'partially_refunded'
  | 'refunded';
export type PaginatedDataCollection<TKey, TValue> = LengthAwarePaginator<TKey, TValue>;
export type PaymentData = {
  id: string;
  order_id: string;
  status: PaymentStatus;
  gateway: string;
  method: string;
  amount: Money;
  expires_at: string | null;
  next_action: NextActionData;
  failure_code: string | null;
};
export type PaymentMethodConfirmation = 'sync' | 'async';
export type PaymentMethodOfferData = {
  method: string;
  gateway: string;
  confirmation: PaymentMethodConfirmation;
  confirmation_window_minutes: number | null;
};
export type PaymentStatus = 'initiated' | 'confirmed' | 'failed' | 'expired';
export type PayoutData = {
  id: string;
  gateway: string;
  gateway_reference: string;
  amount: Money;
  status: PayoutStatus;
  executed_at: string | null;
  reconciled_at: string | null;
  discrepancy: Money | null;
  created_at: string;
};
export type PayoutStatus = 'pending' | 'in_transit' | 'paid' | 'failed' | 'canceled';
export type ProblemData = {
  type: string;
  title: string;
  status: number;
  detail: string;
  code: string;
  correlation_id?: string;
};
export type PromoCodeCheckData = {
  valid: boolean;
  discount: Money | null;
  reason_code: string | null;
};
export type PromoCodeData = {
  id: string;
  code: string;
  discount_type: string;
  discount_value: number;
  currency: string | null;
  usage_limit: number | null;
  usage_count: number;
  valid_from: string | null;
  valid_to: string | null;
  created_at: string;
};
export type PromoCodeDiscountType = 'percentage' | 'fixed_amount';
export type QrVerificationOutcome =
  | 'valid'
  | 'qr_signature_invalid'
  | 'qr_key_revoked'
  | 'ticket_rotation_stale'
  | 'ticket_not_found'
  | 'ticket_canceled'
  | 'ticket_refunded'
  | 'ticket_status_unknown';
export type QrVerificationResultData = {
  outcome: QrVerificationOutcome;
  ticket_id: string | null;
  event_id: string | null;
  rotation_counter: number | null;
};
export type ReconcileBatchData = {
  device_id: string;
  scans: OfflineScanData[];
};
export type RecordScanData = {
  qr_payload: string;
  device_id: string;
  client_scan_id: string;
  scanned_at: string;
};
export type RefreshTokenRequestData = {
  refresh_token: string;
};
export type RefundCommissionPolicy = 'returned' | 'retained';
export type RefundData = {
  id: string;
  payment_id: string;
  order_id: string;
  status: RefundStatus;
  amount: Money;
  commission_amount: Money;
  reason: string | null;
  gateway_reference: string | null;
  failure_code: string | null;
  created_at: string;
};
export type RefundStatus = 'pending' | 'processing' | 'completed' | 'failed';
export type RegisterCustomerData = {
  email: string;
  name: string;
  password?: string | null;
  locale?: string | null;
};
export type RegisterTenantDomainData = {
  domain: string;
  is_primary?: boolean;
};
export type RequestPasswordResetData = {
  email: string;
};
export type RoleData = {
  id: string;
  tenant_id: string | null;
  name: string;
  capabilities: string[];
  is_template: boolean;
};
export type RotateSigningKeyData = {
  revoke_previous?: boolean;
};
export type ScanOutcome = 'accepted' | 'duplicate' | 'rejected';
export type ScanOutcomeData = {
  client_scan_id: string;
  outcome: ScanOutcome;
  check_in_id: string | null;
  code: string | null;
  first_scanned_at: string | null;
  first_device_id: string | null;
};
export type SeatData = {
  id: string;
  section: string;
  row: string;
  number: string;
  position_x: number | null;
  position_y: number | null;
};
export type SeatInputData = {
  section: string;
  row: string;
  number: string;
  position_x: number | null;
  position_y: number | null;
};
export type SeatMapData = {
  id: string;
  tenant_id: string;
  venue_id: string;
  name: string;
  layout: Record<string, unknown>;
  seats: SeatData[];
  created_at: string;
  updated_at: string;
};
export type SeatMapSummaryData = {
  id: string;
  venue_id: string;
  name: string;
  seat_count: number;
  created_at: string;
  updated_at: string;
};
export type SigningKeyData = {
  id: string;
  key_version: number;
  secret: string;
  status: string;
  activated_at: string;
  retired_at: string | null;
};
export type SigningKeyListData = {
  data: SigningKeyData[];
};
export type SigningKeyStatus = 'active' | 'retired' | 'revoked';
export type StaffTokenRequestData = {
  email: string;
  password: string;
  mfa_code: string | null;
};
export type StartSubmerchantOnboardingData = {
  gateway: string;
};
export type StorefrontEventData = {
  id: string;
  name: string;
  description: string;
  locale: string;
  start_at: string;
  end_at: string;
  timezone: string;
  is_virtual: boolean;
  virtual_event_url: string | null;
  ticket_types: StorefrontTicketTypeData[];
  cover_image: MediaImageData | null;
  gallery: MediaImageData[];
};
export type StorefrontEventSearchQueryData = {
  q: string | null;
};
export type StorefrontEventSeatData = {
  event_seat_id: string;
  seat_id: string;
  section: string;
  row: string;
  number: string;
  ticket_type_id: string | null;
  status: string;
};
export type StorefrontEventSeatMapData = {
  event_id: string;
  seats: StorefrontEventSeatData[];
};
export type StorefrontTicketTypeData = {
  id: string;
  name: string;
  price: Money;
  sales_start: string | null;
  sales_end: string | null;
};
export type SubmerchantAccountData = {
  id: string;
  gateway: string;
  status: SubmerchantStatus;
  gateway_account_reference: string | null;
  onboarding_url: string | null;
  requirements: string[];
  activated_at: string | null;
  created_at: string;
  updated_at: string;
};
export type SubmerchantStatus =
  | 'pending'
  | 'under_review'
  | 'action_required'
  | 'active'
  | 'rejected'
  | 'disabled'
  | 'needs_review';
export type TenantData = {
  id: string;
  name: string;
  branding_settings: BrandingSettingsData;
  default_locale: string;
  supported_locales: string[];
  enabled_gateways: string[];
  payout_schedule: Record<string, unknown> | null;
  commission_bps: number;
  refund_commission_policy: RefundCommissionPolicy;
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
export type TenantMediaUploadData = {
  file: File;
  collection: string;
};
export type TicketData = {
  id: string;
  ticket_type_id: string;
  event_seat_id: string | null;
  status: string;
  attendee_name: string | null;
  issued_at: string;
  qr_payload: string;
};
export type TicketManifestEntryData = {
  ticket_id: string;
  status: string;
  rotation_counter: number;
  updated_at: string;
};
export type TicketStatus = 'issued' | 'canceled' | 'refunded';
export type TicketTypeAvailabilityData = {
  ticket_type_id: string;
  available: number;
  on_sale: boolean;
};
export type TicketTypeData = {
  id: string;
  tenant_id: string;
  event_id: string;
  name: string;
  price: Money;
  sales_start: string | null;
  sales_end: string | null;
  requires_seat: boolean;
  created_at: string;
  updated_at: string;
};
export type TicketTypeInventoryData = {
  quantity: number;
  sold: number;
  held: number;
};
export type TokenPairData = {
  access_token: string;
  refresh_token: string;
  token_type: string;
  expires_in: number;
};
export type UpdateEventData = {
  name?: Record<string, string>;
  description?: Record<string, string>;
  venue_id?: string | null;
  is_virtual?: boolean;
  virtual_event_url?: string | null;
  start_at?: string;
  end_at?: string;
  timezone?: string;
  async_payment_policy?: AsyncPaymentPolicyData;
  seat_map_id?: string | null;
};
export type UpdateEventSeatOperationData = {
  event_seat_id: string;
  op: string;
  ticket_type_id: string | null;
};
export type UpdateEventSeatsData = {
  operations: UpdateEventSeatOperationData[];
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
  commission_bps?: number;
  refund_commission_policy?: RefundCommissionPolicy;
};
export type UpdateTenantDomainData = {
  is_primary?: boolean;
};
export type UpdateTicketTypeData = {
  name?: string;
  price?: Money;
  sales_start?: string | null;
  sales_end?: string | null;
  requires_seat?: boolean;
  quantity?: number;
};
export type UpdateVenueData = {
  name?: string;
  address?: string;
  city?: string;
  country?: string;
  capacity?: number;
};
export type UpsertPromoCodeData = {
  code?: string;
  discount_type?: string;
  discount_value?: number;
  currency?: string | null;
  usage_limit?: number | null;
  valid_from?: string | null;
  valid_to?: string | null;
};
export type UpsertSeatMapData = {
  name: string;
  layout: Record<string, unknown>;
  seats: SeatInputData[];
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
export type VenueData = {
  id: string;
  tenant_id: string;
  name: string;
  address: string;
  city: string;
  country: string;
  capacity: number;
  created_at: string;
  updated_at: string;
};
export type VerifyCheckInQrData = {
  qr_payload: string;
};
export type WebhookKind = 'confirmed' | 'failed' | 'refund_completed' | 'refund_failed';
