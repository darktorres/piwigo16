#!/bin/bash

# Drops and recreates PIWIGO_DB_BASE as a genuinely empty database -- no
# fixture load, matching what a fresh CI mysql/postgres service container
# looks like before install.php has ever run against it. Used by
# tests/Browser/InstallTest.php to exercise the real install flow locally
# without needing CI's own dedicated fresh-container job; tools/
# reimport-fixture.sh (drop+recreate+load fixture) is the counterpart that
# restores the DB afterward for every other Browser test.
#
# Same DROP/CREATE shape as IntegrationTestCase::dropAndCreateDatabase() --
# pgsql connects to the `postgres` maintenance database and uses `WITH
# (FORCE)` (Postgres 13+) to terminate any other backend still attached to
# PIWIGO_DB_BASE, since a bare DROP DATABASE fails outright otherwise;
# mysqli connects with no database selected, same reason a bare `mysql
# $dbname -e ...` can't run a DROP against the database it's connected to.
#
# Reads DB credentials from .env.test, same variables reimport-fixture.sh
# uses.

set -euo pipefail

set -a
source .env.test
set +a

if [ "${PIWIGO_DB_DRIVER:-mysqli}" = "pgsql" ]; then
  psql_args=(-v ON_ERROR_STOP=1 -q -U"${PIWIGO_DB_USER}" -h"${PIWIGO_DB_HOST}" -d postgres)
  if [ -n "${PIWIGO_DB_PASSWORD:-}" ]; then
    export PGPASSWORD="${PIWIGO_DB_PASSWORD}"
  fi

  psql "${psql_args[@]}" -c "DROP DATABASE IF EXISTS \"${PIWIGO_DB_BASE}\" WITH (FORCE);"
  psql "${psql_args[@]}" -c "CREATE DATABASE \"${PIWIGO_DB_BASE}\" WITH ENCODING 'UTF8';"
else
  mysql_args=(-h"${PIWIGO_DB_HOST}" -u"${PIWIGO_DB_USER}")
  if [ -n "${PIWIGO_DB_PASSWORD:-}" ]; then
    mysql_args+=(-p"${PIWIGO_DB_PASSWORD}")
  fi

  mysql "${mysql_args[@]}" -e "DROP DATABASE IF EXISTS \`${PIWIGO_DB_BASE}\`; CREATE DATABASE \`${PIWIGO_DB_BASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
fi
