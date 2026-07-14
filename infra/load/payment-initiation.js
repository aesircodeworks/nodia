// Payment initiation under load, with Idempotency-Key replays mixed in.
//
// Each iteration walks the real funnel (hold, order, payment) rather than
// posting payments at a pre-built order, because a payment is only reachable
// through a hold and an order, and a load script that skips the funnel measures
// a code path no client can take.
//
// One iteration in ten replays its payment request verbatim, reusing the same
// Idempotency-Key. That is the shape a flaky mobile client produces, and it is
// the case the probes then check from the database side: a replay must return
// the original payment, never charge a second time.

import http from 'k6/http';
import { Counter } from 'k6/metrics';
import { BASE_URL, PAY_TICKET_TYPE_ID, STANDARD_EVENT_ID, headers, scaled } from './lib/fixture.js';

const paymentsCreated = new Counter('payments_created');
const paymentsReplayed = new Counter('payments_replayed');
const replayMismatched = new Counter('payments_replay_mismatched');
const rateLimited = new Counter('responses_rate_limited');
const unexpected = new Counter('responses_unexpected');

const CUSTOMER_COUNT = scaled(20, 5);

export const options = {
  scenarios: {
    payment_initiation: {
      executor: 'constant-arrival-rate',
      rate: scaled(50, 5),
      timeUnit: '1s',
      duration: scaled('60s', '20s'),
      preAllocatedVUs: scaled(100, 10),
      maxVUs: scaled(300, 30),
    },
  },
  thresholds: {
    responses_rate_limited: [{ threshold: 'count==0', abortOnFail: true }],
    responses_unexpected: ['count==0'],
    // A replay that does not return the original payment is the failure this
    // scenario exists to catch, so it is a threshold and not just a log line.
    payments_replay_mismatched: ['count==0'],
    http_req_duration: [`p(95)<${scaled(800, 3000)}`],
  },
};

// Customers are registered over HTTP rather than seeded, so the fixture never
// has to fabricate a password hash. Each VU takes one and reuses it.
export function setup() {
  const customers = [];

  for (let i = 0; i < CUSTOMER_COUNT; i++) {
    const email = `load-${Date.now()}-${i}@load.localhost`;
    const password = 'load-fixture-password';

    const registration = http.post(
      `${BASE_URL}/v1/customers`,
      JSON.stringify({ email, name: `Load Customer ${i}`, password }),
      { headers: headers() },
    );

    if (registration.status !== 201) {
      throw new Error(
        `could not register load customer: ${registration.status} ${registration.body}`,
      );
    }

    const token = http.post(
      `${BASE_URL}/v1/auth/customer/token`,
      JSON.stringify({ email, password }),
      { headers: headers() },
    );

    if (token.status !== 200) {
      throw new Error(`could not authenticate load customer: ${token.status} ${token.body}`);
    }

    customers.push(token.json('access_token'));
  }

  return { tokens: customers };
}

export default function (data) {
  const accessToken = data.tokens[(__VU - 1) % data.tokens.length];
  const authed = headers({ Authorization: `Bearer ${accessToken}` });

  const hold = http.post(
    `${BASE_URL}/v1/storefront/holds`,
    JSON.stringify({
      event_id: STANDARD_EVENT_ID,
      items: [{ ticket_type_id: PAY_TICKET_TYPE_ID, quantity: 1 }],
    }),
    { headers: authed, tags: { name: 'POST /v1/storefront/holds' } },
  );

  if (hold.status === 429) {
    rateLimited.add(1);
    return;
  }

  if (hold.status !== 201) {
    unexpected.add(1);
    console.error(`unexpected hold response ${hold.status}: ${hold.body}`);
    return;
  }

  const order = http.post(
    `${BASE_URL}/v1/storefront/orders`,
    JSON.stringify({
      hold_id: hold.json('id'),
      attendee_names: { [PAY_TICKET_TYPE_ID]: ['Load Attendee'] },
    }),
    { headers: authed, tags: { name: 'POST /v1/storefront/orders' } },
  );

  if (order.status !== 201) {
    unexpected.add(1);
    console.error(`unexpected order response ${order.status}: ${order.body}`);
    return;
  }

  const orderId = order.json('id');
  const idempotencyKey = `load-${__VU}-${__ITER}-${Date.now()}`;
  const payload = JSON.stringify({ method: 'card', details: { token: 'tok_approve' } });
  const paymentParams = {
    headers: headers({ Authorization: `Bearer ${accessToken}`, 'Idempotency-Key': idempotencyKey }),
    tags: { name: 'POST /v1/storefront/orders/{order}/payments' },
  };

  const payment = http.post(
    `${BASE_URL}/v1/storefront/orders/${orderId}/payments`,
    payload,
    paymentParams,
  );

  if (payment.status !== 201) {
    unexpected.add(1);
    console.error(`unexpected payment response ${payment.status}: ${payment.body}`);
    return;
  }

  paymentsCreated.add(1);

  if (__ITER % 10 !== 0) {
    return;
  }

  const replay = http.post(
    `${BASE_URL}/v1/storefront/orders/${orderId}/payments`,
    payload,
    paymentParams,
  );

  // The contract: a replayed key returns the original payment with 200, never a
  // second 201 and never a new payment id.
  if (replay.status === 200 && replay.json('id') === payment.json('id')) {
    paymentsReplayed.add(1);
    return;
  }

  replayMismatched.add(1);
  console.error(`replay of ${idempotencyKey} returned ${replay.status}: ${replay.body}`);
}

export function handleSummary(data) {
  return {
    [__ENV.SUMMARY_OUT || 'summary-payment-initiation.json']: JSON.stringify(data, null, 2),
  };
}
