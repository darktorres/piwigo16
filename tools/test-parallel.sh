#!/usr/bin/env bash
# Run all test suites concurrently and exit non-zero if any fails.
#
# Layout:
#   IntegrationParallel — paratest, DB + HTTP tests with per-worker
#                         databases & .env routing (TEST_TOKEN-driven).
#   IntegrationSerial   — phpunit, install/upgrade lifecycle (single proc).
#                         Runs concurrently with the parallel suite but
#                         uses the unsuffixed `.env.test` / `piwigo_fixture_build`
#                         which the parallel workers never touch.
#   Unit                — paratest, ~1.5 s either way.
#
# Wall-clock = max(parallel, serial, unit). Tweak worker count via PROCS.

set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PROCS="${PROCS:-8}"

declare -A PID

start() {
    local key="$1"
    shift
    "$@" &
    PID[$key]=$!
}

start parallel vendor/bin/paratest --testsuite IntegrationParallel --processes "$PROCS"
start serial   vendor/bin/phpunit  --testsuite IntegrationSerial
start unit     vendor/bin/paratest --testsuite Unit --processes "$PROCS"

fail=0
for key in parallel serial unit; do
    pid=${PID[$key]}
    if ! wait "$pid"; then
        echo "Suite '$key' (pid $pid) failed" >&2
        fail=1
    fi
done

exit "$fail"
