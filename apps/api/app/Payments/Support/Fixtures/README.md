# Gateway fixture harness

This directory is the gateway-agnostic machinery for recording, replaying,
and verifying sandbox HTTP exchanges (stage-08d plan, Slices 2 and 8). It
has no knowledge of any specific gateway; a gateway becomes usable here by
implementing `GatewayFixtureRecorder` and binding it in
`config('payments.fixture_recorders')`.

- `GatewayFixtureLoader`: replays recorded fixtures from
  `tests/Fixtures/gateways/{slug}/*.json` in CI and locally. A request the
  fixture set does not cover fails loudly instead of hitting the network.
- `GatewayFixtureSanitizer`: scans fixtures for known secret shapes
  (bearer tokens, `sk_/pk_/rk_` keys, AWS access key IDs, PAN-like digit
  runs) and redacts them before a fixture is written. The scan runs
  permanently in CI over the whole fixture tree.
- `GatewayFixtureRecorder`: the port a real gateway adapter implements to
  hit its live sandbox and return raw exchanges for a named scenario.
- `GatewayFixtureDriftDetector`: replays a recorded scenario against the
  live sandbox (through a bound recorder) and diffs the actual exchanges
  against the committed fixture, reporting every divergence.

## Obtaining sandbox credentials

1. Request sandbox access for the launch gateway through the account owner
   named in the launch gateway ADR (`docs/decisions/`). Do this well ahead
   of when you need it: sub-merchant KYC sandbox approval in particular has
   external lead time.
2. Store the sandbox API key and the webhook signing secret in the team
   secret store, never in a committed file or a fixture. Local development
   reads them from environment variables surfaced through
   `config/payments.php` (the same pattern the fake gateway's config
   entries already follow).
3. Never commit sandbox credentials to `.env.example` or any fixture file.
   The sanitizer guard (`GatewayFixtureSanitizer::scan()`) runs in CI and
   fails the build if a known-format credential shape appears anywhere
   under `tests/Fixtures/gateways/`, but treat that as a safety net, not
   the primary control.

## Recording fixtures

Recording is a manual, local act. It never runs in CI:
`php artisan gateway:record-fixtures` refuses immediately when the `CI`
environment variable is set.

1. Export the sandbox credentials for the gateway you are recording
   against into your shell (or your local `.env`), matching whatever
   `config('payments.fixture_recorders.{slug}')`'s bound
   `GatewayFixtureRecorder` implementation expects.
2. Run:

   ```sh
   php artisan gateway:record-fixtures {slug} {scenario}
   ```

   This calls the bound recorder's `record()` method, sanitizes every
   returned exchange with `GatewayFixtureSanitizer::redact()`, and writes
   `tests/Fixtures/gateways/{slug}/{scenario}-{index}.json` for each
   exchange the scenario produced.
3. Diff the newly written fixtures before committing. Confirm no secret
   material survived redaction (the sanitizer catches known shapes; a
   gateway-specific format it does not recognize is still your
   responsibility to catch by eye).
4. Run `./vendor/bin/pest --filter=GatewayFixtureSanitizerTest` (or the
   full sanitizer guard test) to confirm the fixture tree still passes
   before committing.

## Checking for sandbox drift

The drift detector is the manually triggered (and optionally
nightly-scheduled) suite from stage-08d plan Slice 8. It is never run
CI-per-PR: it makes real network calls against the live sandbox.

```sh
php artisan gateway:check-fixture-drift {slug} {scenario}
```

This replays the named scenario against the live sandbox through the
bound recorder and diffs every exchange against the committed fixture. A
clean run exits 0 with "No drift detected"; any divergence (a changed
response body, a different exchange count, or a scenario with no fixture
yet) exits non-zero and lists what diverged. Treat a drift report as a
signal to either re-record the fixture (if the gateway's real behavior
changed intentionally) or investigate a regression (if it did not).

## Rotating the webhook signing secret

The verifier seam (Stage 8a) must support at least two active signing
secrets at once so rotation never opens a rejection window:

1. Generate the new secret in the gateway's dashboard or API without
   revoking the old one.
2. Add the new secret to the secret store and configure the verifier to
   accept signatures produced by either the old or the new secret
   (`config('payments.gateways.{slug}.webhook_secrets')`, a list, not a
   single value).
3. Confirm live webhook deliveries verify successfully against the new
   secret (check delivery logs or trigger a test event from the gateway's
   dashboard, if it offers one).
4. Once confident the new secret is in active use, revoke the old secret
   in the gateway's dashboard and remove it from the verifier's accepted
   list.
5. If the chosen gateway does not support overlapping active secrets,
   there is an unavoidable rejection window while both sides swap: persist
   raw webhook bodies only after verification, as this pipeline already
   does (system-design 7.4, 13), and expect a brief run of `401` responses
   from the ingestion route during that window rather than a lost delivery
   (the gateway retries on non-2xx).
