#!/bin/bash

# Reimports the committed tests/Fixtures/piwigo-16.x.sql into the local test DB
# and waits for it to settle before returning. Used as a preceding step for
# composer test:browser/test:visual so those suites are self-contained and
# never depend on whatever state a previous manual run happened to leave the
# DB in (VR in particular produces a wave of false diffs if a CRUD-mutating
# test:browser run happened first without a reimport in between).
#
# Reads DB credentials from .env.test, same variables IntegrationTestCase.php
# uses (PIWIGO_DB_HOST/USER/PASSWORD/BASE/PREFIX) — no PIWIGO_DB_PORT support,
# matching that class, which doesn't read one either.

set -euo pipefail

set -a
source .env.test
set +a

mysql_args=(-h"${PIWIGO_DB_HOST}" -u"${PIWIGO_DB_USER}")
if [ -n "${PIWIGO_DB_PASSWORD:-}" ]; then
  mysql_args+=(-p"${PIWIGO_DB_PASSWORD}")
fi

mysql "${mysql_args[@]}" "${PIWIGO_DB_BASE}" < tests/Fixtures/piwigo-16.x.sql

until mysql "${mysql_args[@]}" "${PIWIGO_DB_BASE}" -e "SELECT COUNT(*) FROM ${PIWIGO_DB_PREFIX}images;" > /dev/null 2>&1; do
  sleep 0.5
done
