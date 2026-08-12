<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * The one shared implementation of "what does a freshly-reimported
 * piwigo_test DB need corrected, on top of the raw tests/Fixtures/
 * piwigo-17.0.sql (or its pgsql sibling) import" -- used by both
 * IntegrationTestCase::loadFixture() (Integration/Contract suites) and
 * tools/normalize-fixture.php (the CLI entry point tools/reimport-fixture.sh
 * calls for the Browser/Visual suites). Previously 2 separate,
 * independently-written implementations that had drifted apart (the
 * shell one also seeded a `themes` row for a bug now fixed at the
 * source in ThemeCatalog::getPwgThemes() instead, and normalized
 * categories.lastmodified, which the PHP one never did) -- unified here
 * so "what does a fresh reimport produce" has exactly one answer across
 * every suite that reimports.
 */
final class FixtureNormalizer
{
    public static function apply(Connection $conn, string $dbDriver, string $realRoot): void
    {
        // `sites` id=1's own `galleries_url` is committed in the fixture
        // as an absolute filesystem path (Piwigo\Core\Paths::$root .
        // 'galleries/', matching exactly what Admin\Install\InstallWizard
        // seeds it with on a real install) -- inherently tied to wherever
        // *that* install's checkout lived, not portable data. Every
        // checkout of this repo lives at a different path, so this is
        // corrected here, at fixture-load time, the same "environment-
        // injected, never fixture-baked" treatment as PIWIGO_TEST_NOW.
        $conn->executeStatement(
            'UPDATE sites SET galleries_url = ? WHERE id = 1',
            [$realRoot . 'galleries/']
        );

        // `categories.lastmodified` is a server-enforced ON UPDATE
        // CURRENT_TIMESTAMP-equivalent column on both platforms --
        // invisible to and unfixable by any PHP-level Env::now()/
        // PIWIGO_TEST_NOW freeze, since the server stamps it with the
        // real wall clock the instant any real INSERT (a real fixture
        // regen re-runs the full install flow) creates the row. Every
        // real `composer test:fixture-regen` bakes in whatever moment
        // that regen happened to run at, so this is normalized here to
        // whatever value the committed VisualRegressionTest .snap
        // baselines were captured against -- needs to change in lockstep
        // with those baselines, not independently.
        if ($dbDriver === 'pgsql') {
            // categories has a real BEFORE UPDATE trigger
            // (trg_categories_lastmodified, the pgsql port of the mysql
            // schema's ON UPDATE CURRENT_TIMESTAMP) that unconditionally
            // sets NEW.lastmodified = now() on every UPDATE, silently
            // clobbering this literal value -- session_replication_role
            // = replica (already used the same way, for FK checks,
            // elsewhere in this codebase) suppresses user-defined
            // triggers for the statements in between. 3 separate
            // executeStatement() calls wrapped in transactional(), not
            // one semicolon-joined string -- unlike a raw `psql -c`
            // invocation, Doctrine's DBAL layer isn't guaranteed to
            // accept a multi-statement batch in a single call, and
            // wrapping in a real transaction (matching the original raw-
            // SQL BEGIN/COMMIT this replaces) means a failed UPDATE can't
            // leave the session stuck in replica mode -- a plain (non-
            // LOCAL) SET's effect is itself undone if its own transaction
            // aborts.
            $conn->transactional(static function (Connection $conn): void {
                $conn->executeStatement('SET session_replication_role = replica');
                $conn->executeStatement("UPDATE categories SET lastmodified = '2026-08-02 00:00:00'");
                $conn->executeStatement('SET session_replication_role = DEFAULT');
            });
        } else {
            $conn->executeStatement("UPDATE categories SET lastmodified = '2026-08-02 00:00:00'");
        }
    }
}
