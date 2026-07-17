// Waiting room admission (Stage 10): a crowd larger than the inventory joins
// the queue, polls until the gatekeeper admits it, and then spends its
// admission token on a hold.
//
// The gatekeeper is a scheduled command and the Compose stack runs no scheduler,
// so run.sh drives `onsale:gatekeeper` in a loop for the duration of this
// scenario. Without that loop nobody is ever admitted and this script measures
// nothing but the poll endpoint.
//
// The admission-rate accuracy target is checked by run.sh rather than here: it
// needs the wall-clock window the whole scenario ran for, which a k6 threshold
// cannot see.

import http from 'k6/http';
import { sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import { BASE_URL, QUEUE_EVENT_ID, QUEUE_TICKET_TYPE_ID, headers, scaled } from './lib/fixture.js';

const queueJoined = new Counter('queue_joined');
const queueAdmitted = new Counter('queue_admitted');
const admissionWait = new Trend('queue_admission_wait_seconds');
const admittedHolds = new Counter('admitted_holds_created');
const admittedHoldsRejected = new Counter('admitted_holds_rejected');
const rateLimited = new Counter('responses_rate_limited');
const unexpected = new Counter('responses_unexpected');

const ENTRANTS = scaled(500, 25);
const POLL_INTERVAL_SECONDS = 2;
const MAX_WAIT_SECONDS = scaled(120, 60);

export const options = {
  scenarios: {
    waiting_room: {
      executor: 'per-vu-iterations',
      vus: ENTRANTS,
      iterations: 1,
      maxDuration: `${MAX_WAIT_SECONDS + 30}s`,
    },
  },
  thresholds: {
    responses_rate_limited: [{ threshold: 'count==0', abortOnFail: true }],
    responses_unexpected: ['count==0'],
    // An admitted entrant holding a valid token must be able to hold. A
    // rejection here would mean the token signing path is racing under load.
    admitted_holds_rejected: ['count==0'],
    // Every waiting entrant polls on an interval, so the poll is the highest
    // volume endpoint in this scenario and gets its own latency target.
    'http_req_duration{name:poll}': [`p(95)<${scaled(200, 1000)}`],
  },
};

export default function () {
  const entry = http.post(
    `${BASE_URL}/v1/storefront/events/${QUEUE_EVENT_ID}/queue-entries`,
    JSON.stringify({}),
    { headers: headers(), tags: { name: 'join' } },
  );

  if (entry.status === 429) {
    rateLimited.add(1);
    return;
  }

  if (entry.status !== 201) {
    unexpected.add(1);
    console.error(`unexpected queue join response ${entry.status}: ${entry.body}`);
    return;
  }

  queueJoined.add(1);

  const entryId = entry.json('id');
  const joinedAt = Date.now();
  let admissionToken = null;

  while ((Date.now() - joinedAt) / 1000 < MAX_WAIT_SECONDS) {
    sleep(POLL_INTERVAL_SECONDS);

    const poll = http.get(`${BASE_URL}/v1/storefront/queue-entries/${entryId}`, {
      headers: headers(),
      tags: { name: 'poll' },
    });

    if (poll.status === 429) {
      rateLimited.add(1);
      return;
    }

    if (poll.status !== 200) {
      unexpected.add(1);
      console.error(`unexpected queue poll response ${poll.status}: ${poll.body}`);
      return;
    }

    if (poll.json('status') === 'admitted') {
      admissionToken = poll.json('admission_token');
      queueAdmitted.add(1);
      admissionWait.add((Date.now() - joinedAt) / 1000);
      break;
    }
  }

  // Never admitted inside the window. Not an error: the queue exists to hold a
  // crowd back, and at a fixed admission rate most of a large crowd is still
  // waiting when the scenario ends. run.sh is what decides whether the rate the
  // gatekeeper actually achieved matched the configured one.
  if (admissionToken === null) {
    return;
  }

  const hold = http.post(
    `${BASE_URL}/v1/storefront/holds`,
    JSON.stringify({
      event_id: QUEUE_EVENT_ID,
      items: [{ ticket_type_id: QUEUE_TICKET_TYPE_ID, quantity: 1 }],
    }),
    {
      headers: headers({ 'X-Admission-Token': admissionToken }),
      tags: { name: 'admitted-hold' },
    },
  );

  if (hold.status === 201) {
    admittedHolds.add(1);
    return;
  }

  admittedHoldsRejected.add(1);
  console.error(`admitted entrant could not hold: ${hold.status} ${hold.body}`);
}

export function handleSummary(data) {
  return { [__ENV.SUMMARY_OUT || 'summary-waiting-room.json']: JSON.stringify(data, null, 2) };
}
