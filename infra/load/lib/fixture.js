// The ids written by fixture.sql, and the request shapes the three scenarios
// share. Kept in one place so a change to the fixture moves one file.

export const BASE_URL = __ENV.BASE_URL || 'http://api:8000';

// The storefront resolves the tenant from the Host header alone, so every
// request below carries it. It is not the URL: k6 connects to the API over the
// Compose network by service name, then presents this host.
export const HOST = 'load.localhost';

export const STANDARD_EVENT_ID = '0199a000-0000-7000-8000-00000000e010';
export const HOLD_TICKET_TYPE_ID = '0199a000-0000-7000-8000-00000000e011';
export const PAY_TICKET_TYPE_ID = '0199a000-0000-7000-8000-00000000e013';
export const QUEUE_EVENT_ID = '0199a000-0000-7000-8000-00000000e020';
export const QUEUE_TICKET_TYPE_ID = '0199a000-0000-7000-8000-00000000e021';

export function headers(extra = {}) {
  return { Host: HOST, 'Content-Type': 'application/json', ...extra };
}

export function profile() {
  return __ENV.PROFILE || 'full';
}

// CI runs a reduced profile on a shared runner where the absolute numbers mean
// nothing; it exists to prove the scripts still execute and the probes still
// pass. Latency thresholds are relaxed there for the same reason, but the
// correctness checks are not: a duplicate payment on a slow runner is still a
// duplicate payment.
export function scaled(full, ci) {
  return profile() === 'ci' ? ci : full;
}
