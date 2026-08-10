#!/bin/bash

# Placeholder restore drill (docs/PLAN.md P4). "Restore drills" per
# the plan means restoring a real backup — but the actual backup/restore CLI
# is P12, three phases away, and doesn't exist yet. This proves the honest
# subset available today: restoring the tracked tests/Fixtures/piwigo-17.0.sql
# mysqldump into a scratch DB, then asserting row counts + a schema smoke
# query, so the drill mechanism and assertions are proven correct now.
# Deliberately not rewired onto `bin/piwigo backup:restore` even though
# P12 has long since landed — see docs/REFERENCE.md's Restore section for
# why this stays a lean, PHP-dependency-free script.
#
# Reads DB credentials from .env.test, same convention as
# tools/reimport-fixture.sh. Never touches PIWIGO_DB_BASE itself — creates
# and drops its own scratch DB alongside it.

set -euo pipefail

set -a
source .env.test
set +a

SCRATCH_DB="${PIWIGO_DB_BASE}_restore_drill"

mysql_args=(-h"${PIWIGO_DB_HOST}" -u"${PIWIGO_DB_USER}")
if [ -n "${PIWIGO_DB_PASSWORD:-}" ]; then
  mysql_args+=(-p"${PIWIGO_DB_PASSWORD}")
fi

cleanup() {
  mysql "${mysql_args[@]}" -e "DROP DATABASE IF EXISTS \`${SCRATCH_DB}\`;" || true
}
trap cleanup EXIT

mysql "${mysql_args[@]}" -e "DROP DATABASE IF EXISTS \`${SCRATCH_DB}\`; CREATE DATABASE \`${SCRATCH_DB}\`;"
mysql "${mysql_args[@]}" "${SCRATCH_DB}" < tests/Fixtures/piwigo-17.0.sql

image_count=$(mysql "${mysql_args[@]}" "${SCRATCH_DB}" -N -e "SELECT COUNT(*) FROM images;")
user_count=$(mysql "${mysql_args[@]}" "${SCRATCH_DB}" -N -e "SELECT COUNT(*) FROM users;")

if [ "${image_count}" -lt 1 ] || [ "${user_count}" -lt 1 ]; then
  echo "restore-drill: FAILED — restored DB has ${image_count} images, ${user_count} users (expected >= 1 each)" >&2
  exit 1
fi

# Smoke query: a join across tables proves the schema itself (not just raw
# row presence) survived the restore intact.
mysql "${mysql_args[@]}" "${SCRATCH_DB}" -N -e \
  "SELECT i.id FROM images i JOIN image_category ic ON ic.image_id = i.id LIMIT 1;" \
  > /dev/null

echo "restore-drill: OK — restored ${image_count} images, ${user_count} users into scratch DB, schema smoke query passed"
