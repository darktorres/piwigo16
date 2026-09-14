#!/bin/bash

# composer's array-form scripts forward any trailing CLI args (after `--`)
# to *every* command in the array, not just the last one -- passing a
# test path/filter this way used to also land on `bun run build`, which
# then tries to treat it as a build entry and fails. Wrapping the real
# steps in this single script keeps composer's own forwarding to one
# command (this script), and only this script's own "$@" -- scoped here
# to the pest invocation alone -- decides what pest sees.

set -e

bun run build
bash tools/reimport-fixture.sh
php vendor/bin/pest --testsuite Browser --exclude-group=fixture-regen --exclude-group=visual-regression --exclude-group=golden-html-snapshot --exclude-group=install-flow "$@"
