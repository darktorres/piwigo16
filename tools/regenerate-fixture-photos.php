<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Tests\Browser\Helpers\FixturePhotoGenerator;

/**
 * Regenerates the 5 core fixture photos' physical JPEG bytes at whatever
 * path the just-reimported tests/Fixtures/piwigo-17.0.sql currently
 * declares for piwigo_images ids 1-5 -- run by tools/reimport-fixture.sh
 * right after the SQL import, so composer test:browser/test:visual are
 * truly self-contained: that script's own docblock already promises those
 * suites "never depend on whatever state a previous manual run happened to
 * leave the DB in", but until now that promise only covered the DB rows,
 * not the gitignored upload/ files those rows point at, which persisted
 * untouched across reimports. A stale (or missing) upload/ file relative
 * to whatever the current FixturePhotoGenerator::COLORS/label convention
 * produces is exactly what let the committed VisualRegressionTest .snap
 * baselines drift out of sync with the real, current fixture content.
 *
 * Reads DB credentials from the environment (PIWIGO_DB_HOST/USER/PASSWORD/
 * BASE/PREFIX/DRIVER) -- same variables tools/reimport-fixture.sh already
 * exports via `set -a; source .env.test; set +a` before invoking this
 * script, and the same names Tests\Browser\Helpers\BrowserTestHelpers's own
 * direct-mysqli/pgsql test helpers use. PIWIGO_DB_DRIVER branching matches
 * IntegrationTestCase::newPgsqlConnection()'s own connection-string shape.
 */
$host = (string) getenv('PIWIGO_DB_HOST');
$user = (string) getenv('PIWIGO_DB_USER');
$password = getenv('PIWIGO_DB_PASSWORD');
$password = $password === false ? '' : $password;
$base = (string) getenv('PIWIGO_DB_BASE');
$prefix = getenv('PIWIGO_DB_PREFIX');
$prefix = $prefix === false ? 'piwigo_' : $prefix;
$driver = getenv('PIWIGO_DB_DRIVER');
$driver = $driver === 'pgsql' ? 'pgsql' : 'mysqli';

$rows = [];
if ($driver === 'pgsql') {
    $connStringParts = ['host=' . $host, 'user=' . $user, 'dbname=' . $base];
    if ($password !== '') {
        $connStringParts[] = 'password=' . $password;
    }

    $pgConn = pg_connect(implode(' ', $connStringParts), PGSQL_CONNECT_FORCE_NEW);
    if ($pgConn === false) {
        fwrite(STDERR, "regenerate-fixture-photos.php: DB connection failed.\n");
        exit(1);
    }

    $pgResult = pg_query($pgConn, sprintf('SELECT id, path FROM %simages WHERE id BETWEEN 1 AND 5 ORDER BY id', $prefix));
    if ($pgResult === false) {
        fwrite(STDERR, "regenerate-fixture-photos.php: failed to query {$prefix}images.\n");
        exit(1);
    }

    $rows = pg_fetch_all($pgResult, PGSQL_ASSOC);
    pg_close($pgConn);
} else {
    $db = new mysqli($host, $user, $password, $base);
    if ($db->connect_errno !== 0) {
        fwrite(STDERR, "regenerate-fixture-photos.php: DB connection failed: {$db->connect_error}\n");
        exit(1);
    }

    $result = $db->query(sprintf(
        'SELECT id, path FROM %simages WHERE id BETWEEN 1 AND 5 ORDER BY id',
        $db->real_escape_string($prefix)
    ));
    if (! $result instanceof mysqli_result) {
        fwrite(STDERR, "regenerate-fixture-photos.php: failed to query {$prefix}images.\n");
        exit(1);
    }

    while (($row = $result->fetch_assoc()) !== null) {
        $rows[] = $row;
    }

    $db->close();
}

$projectRoot = dirname(__DIR__) . '/';
$count = 0;
foreach ($rows as $row) {
    $id = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
    $path = is_string($row['path'] ?? null) ? $row['path'] : '';
    if ($id < 1 || $id > 5 || $path === '') {
        continue;
    }

    FixturePhotoGenerator::write($id, $projectRoot . $path);
    $count++;
}

echo "regenerate-fixture-photos.php: wrote {$count} fixture photo(s).\n";
