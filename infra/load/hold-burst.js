// Hold creation burst against a single ticket type: the contended
// ticket_type_inventory counter row (system-design 6.3), which is the hottest
// row in the system during an on-sale.
//
// The executor is constant-arrival-rate, not ramping-vus, on purpose. A crowd
// arriving for an on-sale does not slow down because the API is slow; it keeps
// arriving. A VU-based executor would back off as latency rose and quietly
// offer less load than the target claims.

import http from 'k6/http';
import { Counter } from 'k6/metrics';
import {
  BASE_URL,
  HOLD_TICKET_TYPE_ID,
  STANDARD_EVENT_ID,
  headers,
  scaled,
} from './lib/fixture.js';

const holdsCreated = new Counter('holds_created');
const holdsSoldOut = new Counter('holds_rejected_sold_out');
const rateLimited = new Counter('responses_rate_limited');
const unexpected = new Counter('responses_unexpected');

export const options = {
  scenarios: {
    hold_burst: {
      executor: 'constant-arrival-rate',
      rate: scaled(200, 20),
      timeUnit: '1s',
      duration: scaled('60s', '20s'),
      preAllocatedVUs: scaled(200, 20),
      maxVUs: scaled(600, 60),
    },
  },
  thresholds: {
    // A 429 means the run is measuring the storefront rate limiter instead of
    // the counter row. That is not a slow result, it is a void one, so it
    // aborts rather than being reported as a miss.
    responses_rate_limited: [{ threshold: 'count==0', abortOnFail: true }],
    // Losing a race for the last ticket is a 409, which is the system working.
    // Anything that is neither a hold nor a clean sell-out rejection is a bug.
    responses_unexpected: ['count==0'],
    http_req_duration: [`p(95)<${scaled(500, 2000)}`],
  },
};

export default function () {
  const response = http.post(
    `${BASE_URL}/v1/storefront/holds`,
    JSON.stringify({
      event_id: STANDARD_EVENT_ID,
      items: [{ ticket_type_id: HOLD_TICKET_TYPE_ID, quantity: 1 }],
    }),
    { headers: headers(), tags: { name: 'POST /v1/storefront/holds' } },
  );

  if (response.status === 201) {
    holdsCreated.add(1);
    return;
  }

  if (response.status === 429) {
    rateLimited.add(1);
    return;
  }

  if (response.status === 409 && response.json('code') === 'insufficient_inventory') {
    holdsSoldOut.add(1);
    return;
  }

  unexpected.add(1);
  console.error(`unexpected hold response ${response.status}: ${response.body}`);
}

export function handleSummary(data) {
  return { [__ENV.SUMMARY_OUT || 'summary-hold-burst.json']: JSON.stringify(data, null, 2) };
}
