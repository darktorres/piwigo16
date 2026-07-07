#!/bin/bash

# pest-plugin-browser spawns a `node .../playwright run-server` subprocess via a
# shell command line (Symfony Process::fromShellCommandline). Its own stop() sends
# SIGTERM to that shell, which doesn't forward it to the node grandchild, so the
# server is orphaned on essentially every run (known, unmerged upstream bug:
# pestphp/pest-plugin-browser#169). This wrapper force-kills whatever leaked
# instead of relying on the plugin's own (currently broken) cleanup.
#
# Usage: tools/pest-cleanup.sh <args passed straight to vendor/bin/pest>

set -uo pipefail

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
