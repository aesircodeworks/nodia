#!/usr/bin/env bash
#
# Load runner for the three contended paths (stage-12 plan, Slice 8).
#
#   infra/load/run.sh                         # every scenario, full profile
#   infra/load/run.sh --profile ci            # reduced scale, what CI dispatches
#   infra/load/run.sh --scenario hold-burst   # one scenario
#
# What this does beyond invoking k6, and why each part is not optional:
#
#   - Raises the storefront rate limits on the API container. Hold creation is
#     throttled to 10/min/IP by default and all load traffic arrives from one
#     container, so against the defaults a run measures the rate limiter and
#     nothing else. The container is put back on its default limits on exit.
#   - Applies fixture.sql, so every run starts from pristine inventory and one
#     run's leftover holds cannot fail the next run's oversell probe.
#   - Drives `onsale:gatekeeper` in a loop during the waiting-room scenario. The
#     Compose stack runs no scheduler, so nobody is admitted otherwise.
#   - Runs the correctness probes after every scenario and fails the run on any
#     violation, whatever the latency numbers said.

set -euo pipefail

readonly K6_IMAGE="grafana/k6:2.1.0"
readonly REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly LOAD_DIR="${REPO_ROOT}/infra/load"
readonly COMPOSE=(docker compose -f "${REPO_ROOT}/infra/compose/docker-compose.yml" --env-file "${REPO_ROOT}/.env")

PROFILE="full"
SCENARIO="all"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --profile)  PROFILE="$2"; shift 2 ;;
    --scenario) SCENARIO="$2"; shift 2 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

if [[ "${PROFILE}" != "full" && "${PROFILE}" != "ci" ]]; then
  echo "profile must be 'full' or 'ci'" >&2
  exit 2
fi

readonly RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)"
readonly RESULTS_DIR="${LOAD_DIR}/results/${RUN_ID}"
mkdir -p "${RESULTS_DIR}"

# The compose network, so k6 reaches the API by service name instead of
# depending on host networking, which behaves differently on macOS and Linux.
readonly NETWORK="$("${COMPOSE[@]}" ps --format json api | jq -r '.Networks' | head -1)"

log() { printf '\n=== %s\n' "$*"; }

psql_exec() {
  "${COMPOSE[@]}" exec -T postgres psql -U "${DB_USERNAME:-nodia}" -d "${DB_DATABASE:-nodia_api}" "$@"
}

# The API is recreated with raised throttles for the run, then put back. Without
# the restore, an ordinary `make up` afterwards would silently keep load limits.
restore_api() {
  log "restoring the API's default rate limits"
  ONSALE_HOLD_CREATION_IP_RATE_MAX_ATTEMPTS= \
  ONSALE_HOLD_CREATION_CUSTOMER_RATE_MAX_ATTEMPTS= \
  ONSALE_QUEUE_ENTRY_RATE_MAX_ATTEMPTS= \
  ONSALE_QUEUE_POLL_RATE_MAX_ATTEMPTS= \
  ONSALE_BROWSE_RATE_MAX_ATTEMPTS= \
    "${COMPOSE[@]}" up -d api >/dev/null
}

start_api_for_load() {
  log "raising the API's storefront rate limits for the run"

  # High enough that the limiter never engages at the offered rates; the scripts
  # abort on any 429 regardless, so a limit set too low fails loudly.
  export ONSALE_HOLD_CREATION_IP_RATE_MAX_ATTEMPTS=1000000
  export ONSALE_HOLD_CREATION_CUSTOMER_RATE_MAX_ATTEMPTS=1000000
  export ONSALE_QUEUE_ENTRY_RATE_MAX_ATTEMPTS=1000000
  export ONSALE_QUEUE_POLL_RATE_MAX_ATTEMPTS=1000000
  export ONSALE_BROWSE_RATE_MAX_ATTEMPTS=1000000
  # Required, or issuing an admission token throws and every admitted poll 500s.
  export ONSALE_ADMISSION_TOKEN_SIGNING_KEY="${ONSALE_ADMISSION_TOKEN_SIGNING_KEY:-load-fixture-signing-key}"

  "${COMPOSE[@]}" up -d api >/dev/null

  for _ in $(seq 1 30); do
    if curl -fsS localhost:8000/v1/health >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done

  echo "the API did not become healthy" >&2
  exit 1
}

stamp_environment() {
  {
    echo "run_id: ${RUN_ID}"
    echo "profile: ${PROFILE}"
    echo "k6_image: ${K6_IMAGE}"
    echo "host: $(uname -sm)"
    echo "docker_cpus: $(docker info --format '{{.NCPU}}')"
    echo "docker_memory_bytes: $(docker info --format '{{.MemTotal}}')"
    echo "api_image_id: $("${COMPOSE[@]}" images api --format json | jq -r '.[0].ID' 2>/dev/null || echo unknown)"
    echo "git_commit: $(git -C "${REPO_ROOT}" rev-parse HEAD)"
  } | tee "${RESULTS_DIR}/environment.txt"
}

readonly FIXTURE_TENANT_ID="0199a000-0000-7000-8000-00000000e001"
readonly FIXTURE_QUEUE_EVENT_ID="0199a000-0000-7000-8000-00000000e020"

# The waiting room lives in Redis, not PostgreSQL, so fixture.sql cannot reach
# it. Without this, a second run's entrants queue up behind the previous run's
# leftovers in FIFO order and the gatekeeper spends the whole window admitting
# ghosts that are no longer polling: the scenario then reports zero admissions
# and looks like a broken gatekeeper.
#
# The key prefix is Laravel's, so it is discovered rather than hardcoded.
reset_queue_state() {
  "${COMPOSE[@]}" exec -T redis sh -c "
    redis-cli --scan --pattern '*onsale*${FIXTURE_TENANT_ID}*' | xargs -r redis-cli del > /dev/null
    active_key=\$(redis-cli --scan --pattern '*onsale:active' | head -1)
    if [ -n \"\$active_key\" ]; then
      redis-cli srem \"\$active_key\" '${FIXTURE_TENANT_ID}:${FIXTURE_QUEUE_EVENT_ID}' > /dev/null
    fi
  " >/dev/null
}

apply_fixture() {
  log "applying the load fixture"
  psql_exec -v ON_ERROR_STOP=1 -q < "${LOAD_DIR}/fixture.sql"
  reset_queue_state
}

run_probes() {
  local scenario="$1"
  log "correctness probes after ${scenario}"

  local output
  output="$(psql_exec -v ON_ERROR_STOP=1 -t -A -F ' ' < "${LOAD_DIR}/probe.sql")"

  printf '%s\n' "${output}" | tee "${RESULTS_DIR}/probes-${scenario}.txt"

  # Every probe selects only violating rows, so any output at all is a failure.
  if [[ -n "${output//[[:space:]]/}" ]]; then
    echo >&2
    echo "CORRECTNESS PROBE FAILED after ${scenario}. The run is a failure regardless of its latency numbers." >&2
    exit 1
  fi

  echo "all probes clean"
}

run_k6() {
  local scenario="$1"

  log "k6: ${scenario} (profile: ${PROFILE})"

  docker run --rm \
    --network "${NETWORK}" \
    -v "${LOAD_DIR}:/load" \
    -w /load \
    -e "PROFILE=${PROFILE}" \
    -e "BASE_URL=http://api:8000" \
    -e "SUMMARY_OUT=results/${RUN_ID}/summary-${scenario}.json" \
    "${K6_IMAGE}" run "${scenario}.js"
}

# The gatekeeper is scheduled in bootstrap/app.php but the Compose stack has no
# scheduler container, so admission has to be driven for the duration of the
# waiting-room scenario.
start_gatekeeper() {
  log "driving onsale:gatekeeper for the waiting-room scenario"
  (
    while true; do
      "${COMPOSE[@]}" exec -T api php artisan onsale:gatekeeper >/dev/null 2>&1 || true
      sleep 2
    done
  ) &
  GATEKEEPER_PID=$!
}

stop_gatekeeper() {
  if [[ -n "${GATEKEEPER_PID:-}" ]]; then
    kill "${GATEKEEPER_PID}" 2>/dev/null || true
    wait "${GATEKEEPER_PID}" 2>/dev/null || true
    unset GATEKEEPER_PID
  fi
}

# The waiting room's contract is the rate it admits at, and that check needs the
# wall-clock window the scenario actually ran for, which a k6 threshold cannot
# see. Target: within 20 percent of the event's configured admission_rate_per_minute.
#
# This measurement is only meaningful while the queue stays backed up. The full
# profile guarantees that (500 entrants against a rate that admits ~240 inside
# the window); the CI profile's small crowd drains, at which point the observed
# rate would just be describing how fast k6 ran out of entrants. So CI asserts
# only that admission happened at all, which is what proves the wiring works.
check_admission_rate() {
  local summary="${RESULTS_DIR}/summary-waiting-room.json"

  local configured
  configured="$(psql_exec -t -A -c \
    "select on_sale_policy->>'admission_rate_per_minute' from events where id = '0199a000-0000-7000-8000-00000000e020'")"

  local admitted duration_ms observed
  admitted="$(jq -r '.metrics.queue_admitted.values.count // 0' "${summary}")"
  duration_ms="$(jq -r '.state.testRunDurationMs // 0' "${summary}")"

  if [[ "${admitted}" == "0" || "${duration_ms}" == "0" ]]; then
    echo "ADMISSION RATE CHECK FAILED: nobody was admitted; was the gatekeeper running?" >&2
    exit 1
  fi

  observed="$(python3 -c "print(round(${admitted} / (${duration_ms} / 60000.0), 1))")"

  echo "configured admission rate: ${configured}/min"
  echo "observed admission rate:   ${observed}/min (${admitted} admitted)"

  if [[ "${PROFILE}" == "ci" ]]; then
    echo "admission rate accuracy not asserted on the ci profile: the crowd is too small to keep the queue backed up"
    return 0
  fi

  local within
  within="$(python3 -c "print(1 if abs(${observed} - ${configured}) <= ${configured} * 0.2 else 0)")"

  if [[ "${within}" != "1" ]]; then
    echo "ADMISSION RATE CHECK FAILED: ${observed}/min is more than 20 percent from the configured ${configured}/min" >&2
    exit 1
  fi

  echo "admission rate within 20 percent of configured"
}

cleanup() {
  stop_gatekeeper
  restore_api
}
trap cleanup EXIT

main() {
  stamp_environment
  start_api_for_load

  local scenarios=()
  if [[ "${SCENARIO}" == "all" ]]; then
    scenarios=(hold-burst payment-initiation waiting-room)
  else
    scenarios=("${SCENARIO}")
  fi

  for scenario in "${scenarios[@]}"; do
    # Each scenario re-applies the fixture, so scenarios do not inherit each
    # other's consumed inventory and can be run in any order or on their own.
    apply_fixture

    if [[ "${scenario}" == "waiting-room" ]]; then
      start_gatekeeper
      run_k6 "${scenario}"
      stop_gatekeeper
      check_admission_rate
    else
      run_k6 "${scenario}"
    fi

    run_probes "${scenario}"
  done

  log "results in ${RESULTS_DIR}"
}

main "$@"
