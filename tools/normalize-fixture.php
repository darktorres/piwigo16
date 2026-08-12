<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Db\DbConnection;
use Piwigo\Tests\Support\FixtureNormalizer;

/**
 * CLI entry point for FixtureNormalizer::apply() (see that class's own
 * docblock) -- lets tools/reimport-fixture.sh (a shell script, not a
 * PHP process) share the exact same post-processing
 * IntegrationTestCase::loadFixture() uses, rather than maintaining a
 * second, independently-written implementation.
 *
 * Reads DB credentials from the environment (PIWIGO_DB_*) -- same
 * variables tools/reimport-fixture.sh already exports via `set -a;
 * source .env.test; set +a` before invoking this script, matching
 * tools/regenerate-fixture-photos.php's own established shape.
 * DbConnection::build() (via DbCredentials::fromEnv(), a pure getenv()
 * read) picks them up with no extra setup needed here.
 *
 * $argv[1] is the real project root (with a trailing slash), matching
 * FixtureNormalizer::apply()'s own $realRoot param -- reimport-
 * fixture.sh passes "$(pwd)/" the same way it already computes its own
 * real_root for the galleries_url correction.
 */
$realRoot = $argv[1] ?? null;
if (! is_string($realRoot) || $realRoot === '') {
    fwrite(STDERR, "normalize-fixture.php: expected the real project root as \$argv[1].\n");
    exit(1);
}

$driver = getenv('PIWIGO_DB_DRIVER');
$driver = $driver === 'pgsql' ? 'pgsql' : 'mysqli';

FixtureNormalizer::apply(DbConnection::build(), $driver, $realRoot);

echo "normalize-fixture.php: done.\n";
