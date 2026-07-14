#!/bin/bash

# pest-plugin-browser spawns a `node .../playwright run-server` subprocess via a
# shell command line (Symfony Process::fromShellCommandline). Its own stop() sends
# SIGTERM to that shell, which doesn't forward it to the node grandchild, so the
# server is orphaned on essentially every run (known, unmerged upstream bug:
# pestphp/pest-plugin-browser#169). This wrapper force-kills whatever leaked
# instead of relying on the plugin's own (currently broken) cleanup.
#
# A process that's already running when this script STARTS is also treated as
# a leak, not a legitimate concurrent instance: this project's own convention
# is to never run heavy tools (including Browser/VR pest suites) concurrently
# (see feedback_no_concurrent_heavy_tools memory), so a pre-existing
# `playwright run-server` here is essentially always a stale leak from an
# earlier crashed/interrupted/bypassed run -- left alone, it silently
# corrupts the NEXT run's screenshot comparisons (the plugin's own diff view
# falls back to a "?" placeholder instead of a real pixelmatch diff when this
# happens, which is easy to mistake for a real visual regression).
#
# Usage: tools/pest-cleanup.sh <args passed straight to vendor/bin/pest>

set -uo pipefail

stale="$(pgrep -f 'playwright run-server' || true)"
if [ -n "$stale" ]; then
  echo "pest-cleanup.sh: found pre-existing playwright run-server process(es), killing as stale leak(s) before starting:" >&2
  pgrep -fa 'playwright run-server' >&2 || true
  xargs -r kill -TERM <<<"$stale"
  sleep 0.3
  xargs -r kill -KILL <<<"$stale" 2>/dev/null || true
fi

before="$(pgrep -f 'playwright run-server' || true)"

cleanup() {
  local status=$?
  local after leaked
  after="$(pgrep -f 'playwright run-server' || true)"
  leaked="$(comm -13 <(sort <<<"$before") <(sort <<<"$after"))"
  if [ -n "$leaked" ]; then
    xargs -r kill -TERM <<<"$leaked"
    sleep 0.3
    xargs -r kill -KILL <<<"$leaked" 2>/dev/null || true
  fi
  exit "$status"
}
trap cleanup EXIT

# Backgrounding + explicitly forwarding INT/TERM to the child PID (rather than
# relying solely on the EXIT trap) matters because bash defers trap handling
# while blocked on a foreground child — a signal delivered to only this
# wrapper's own PID would otherwise sit unhandled until pest finished naturally.
php vendor/bin/pest "$@" &
child=$!
trap "kill -TERM $child 2>/dev/null" INT TERM
wait "$child"
