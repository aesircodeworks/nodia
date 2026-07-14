# Load targets

Load and soak targets for the three contended paths named in [system-design.md](system-design.md) section 18, and the measured results against them. Stage 12 task 18 of the [API implementation plan](api-implementation-plan.md).

Targets in this document were written before the first run and are not revised to match what the runs produced. Where a run misses a target, the miss is recorded as a miss.

## Tool

k6, run from the official `grafana/k6` Docker image, pinned to `2.1.0` in [infra/load/run.sh](../infra/load/run.sh) and in the CI job. Nothing is installed on the host or on the CI runner: the same image tag runs in both places, so a local run and a CI run execute identical binaries.

k6 was chosen over the alternatives the master plan left open because it expresses the two properties these scenarios actually need. Arrival-rate executors hold a fixed request rate independent of how slow the system gets under contention, which is what a ticketing on-sale looks like (a fixed crowd arrives whether or not the API keeps up); a virtual-user executor would instead back off as latency rose and quietly measure less load than intended. Thresholds are declared per scenario and decide the process exit code, so a run that misses its target fails the CI job rather than printing a number nobody reads.

## Scenarios

Scripts live in `infra/load/` and run against the Compose stack, not the Pest suite. They drive the real HTTP API on `localhost:8000`.

| Scenario               | Script                  | What it contends on                                                                                             |
| ---------------------- | ----------------------- | --------------------------------------------------------------------------------------------------------------- |
| Hold creation burst    | `hold-burst.js`         | One ticket type's `ticket_type_inventory` counter row, the single hottest row in the system (system-design 6.3) |
| Payment initiation     | `payment-initiation.js` | The payment path, with `Idempotency-Key` replays mixed into the traffic                                         |
| Waiting room admission | `waiting-room.js`       | Queue join, poll, and gatekeeper admission at the configured rate (Stage 10)                                    |

Stage 10 shipped in full, so the admission scenario is measured rather than recorded as omitted.

## Targets

Each threshold below is declared in the scenario script and enforced by k6's exit code.

### Hold creation burst

| Target                 | Value                                    | Why this number                                                                                                                                                                                              |
| ---------------------- | ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Sustained arrival rate | 200 holds/second on a single ticket type | An on-sale for a 5,000-seat venue that sells out in under a minute needs roughly 85 holds/second of successful throughput; 200/second of offered load leaves room for the rejected half of a contended burst |
| p95 latency            | under 500ms                              | A hold is a synchronous click in the storefront checkout                                                                                                                                                     |
| Server error rate      | 0                                        | A contended hold that loses its race must return 409 `insufficient_inventory`, never a 5xx                                                                                                                   |
| Oversell               | exactly 0                                | `sold + held <= quantity` after the run, asserted by the probe                                                                                                                                               |

`insufficient_inventory` responses are expected and are not counted as failures once inventory is exhausted: rejecting a hold correctly under contention is the system working, and the correctness probe, not the error rate, is what proves it.

### Payment initiation

| Target                    | Value                                                   | Why this number                                                                                                                                                     |
| ------------------------- | ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sustained arrival rate    | 50 payments/second                                      | Payment initiation trails hold creation by the checkout abandonment rate; 50/second sustains the 200/second hold target through a funnel that converts at a quarter |
| p95 latency               | under 800ms                                             | Higher than a hold: the call crosses the gateway boundary, even against the fake gateway                                                                            |
| Replay correctness        | every replayed `Idempotency-Key` returns 200, never 201 | A retried payment must return the original, not charge again                                                                                                        |
| Duplicate payment effects | exactly 0                                               | One payment row per idempotency key, at most one approved payment per order, ledger balanced, asserted by the probe                                                 |

Ten percent of the offered traffic is a deliberate replay of a key that was already used, which is the shape a flaky mobile client produces.

### Waiting room admission

| Target                            | Value                                                                   | Why this number                                                                                                      |
| --------------------------------- | ----------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| Concurrent entrants held in queue | 500                                                                     | The queue exists precisely to absorb a crowd larger than the inventory                                               |
| Admission rate accuracy           | within 20 percent of the event's configured `admission_rate_per_minute` | The rate is the whole contract of the waiting room: admitting too fast defeats it, too slow strands buyers           |
| Poll p95 latency                  | under 200ms                                                             | Every waiting entrant polls on an interval, so this is the highest-volume endpoint in the scenario                   |
| Admitted holds rejected           | 0 for a valid admission token                                           | An admitted entrant must be able to hold; `admission_invalid` under load would mean the token signing path is racing |

The admission rate is driven by the `onsale:gatekeeper` command. The Compose stack runs no scheduler container, so `run.sh` drives the gatekeeper in a loop for the duration of this scenario; without it nobody is ever admitted and the scenario measures nothing.

## Correctness probes

Throughput numbers are worthless if the system broke its invariants to achieve them. After every run, `infra/load/probe.sh` queries PostgreSQL directly and fails the run on any violation:

1. **No oversell.** No `ticket_type_inventory` row has `sold + held > quantity`.
2. **No duplicate payments.** No `idempotency_key` appears on more than one `payments` row, and no order carries more than one payment in a terminal approved state.
3. **Ledger balanced.** Per tenant and currency, the sum of debits equals the sum of credits in `ledger_entries`.
4. **No orphaned holds.** No `hold_items` row counted into `held` belongs to a hold that is already committed or released.

A run that hits every latency target and fails a probe is a failed run.

## Environment

Load runs are only comparable against a recorded environment. `run.sh` stamps each result with the machine, the Docker resources, the image digest, and the profile used.

The default profile is full scale. CI runs a reduced-scale profile (`--profile ci`) on manual dispatch only, never per commit: it is too slow for a per-commit gate and its numbers are meaningless on a shared runner. The CI job proves the scripts still execute and the probes still pass; it does not produce the numbers in the Results section below.

### Rate limits

The storefront throttles hold creation to 10 requests per minute per IP by default (`config/onsale.php`). Against the default configuration a load run measures the rate limiter and nothing else. `run.sh` refuses to start unless the API is configured with the load profile's raised limits, and fails the run if it sees a 429 it did not ask for.

## Results

Measured 2026-07-14, k6 2.1.0, full profile, every scenario passing its thresholds and every correctness probe clean. `infra/load/run.sh` writes the raw k6 summary JSON and the probe output per run under `infra/load/results/`, which is not committed; the environment stamp below is reproduced from `environment.txt` of those runs.

Environment: Apple Silicon (`Darwin arm64`), Docker with 8 CPUs and 7.75 GiB available, the whole stack (PostgreSQL, Redis, MinIO, and the FrankenPHP API) and the k6 container all sharing that one machine. The load generator competing with the system under test for CPU means these numbers are a floor, not a ceiling: a real deployment separates them.

| Scenario               | Metric                             | Target                                   | Measured                                                      | Headroom               |
| ---------------------- | ---------------------------------- | ---------------------------------------- | ------------------------------------------------------------- | ---------------------- |
| Hold creation burst    | Offered rate                       | 200/s for 60s                            | 199.99/s, 12,001 requests                                     | at target              |
|                        | p95 latency                        | under 500ms                              | 6.71ms                                                        | 75x                    |
|                        | Holds created                      | never more than inventory                | 5,000 against 5,000 available                                 | exact                  |
|                        | Sold-out rejections                | 409, never 5xx                           | 7,001, all `insufficient_inventory`                           | no errors              |
|                        | Oversell                           | 0                                        | 0                                                             | probe clean            |
| Payment initiation     | Offered rate                       | 50/s for 60s                             | 50.0/s, 3,000 orders paid                                     | at target              |
|                        | p95 latency                        | under 800ms                              | 24.07ms                                                       | 33x                    |
|                        | Idempotency replays                | original returned, never a second charge | 301 replays, 301 returned the original payment with 200       | 0 mismatches           |
|                        | Duplicate payment effects          | 0                                        | 0, ledger balanced                                            | probe clean            |
| Waiting room admission | Entrants held in queue             | 500                                      | 500 joined, 266 still waiting when the window closed          | queue stayed backed up |
|                        | Admission rate accuracy            | within 20% of configured                 | configured 120/min, observed 115.8/min (234 admitted in 121s) | within 4%              |
|                        | Poll p95 latency                   | under 200ms                              | 48.58ms                                                       | 4.1x                   |
|                        | Admitted entrants rejected at hold | 0                                        | 0 of 234                                                      | no token races         |

The hold burst is the headline result: at 200 requests per second against a single counter row, the system issued exactly 5,000 holds against 5,000 tickets and rejected the other 7,001 attempts cleanly. Not one oversell, and the `held` counter still agreed with the outstanding holds afterward. The conditional-UPDATE guard in `CreateHold` is doing exactly what Stage 6 claimed for it, under contention rather than in a unit test.

The waiting-room numbers are only meaningful because the crowd exceeds what the gatekeeper can admit inside the window: 500 entrants against 120 admissions per minute leaves the queue backed up for the whole run, so the observed rate measures the gatekeeper rather than how fast k6 ran out of entrants. Sizing the crowd below the admission capacity would make this scenario report a passing number while testing nothing.

### What these runs do not prove

- The API, the database, and the load generator share one laptop. Latency headroom this large will not shrink under real network conditions, but throughput ceilings were never reached, so the numbers say "no contention pathology found up to here", not "the system tops out at 200 holds/second".
- Payment initiation runs against `FakeGateway`, so gateway latency and its failure modes are absent. The Stage 8d real-adapter work is where that gets measured.
- Nothing here is a soak test. The longest run is two minutes; connection-pool exhaustion, memory growth, and queue backlog over hours are unmeasured.
