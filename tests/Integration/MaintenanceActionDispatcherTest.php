<?php

declare(strict_types=1);

// MaintenanceActionDispatcher calls the real l10n() (unqualified, resolves
// to the global namespace) for its own info messages -- a real,
// already-migrated function, but one that needs full app bootstrap
// (LangService/Translator) this isolated integration test deliberately
// doesn't load. Same "minimal stub to load standalone" pattern as
// tests/Integration/PermalinkServiceTest.php, just needing PHP's bracketed
// namespace syntax here since this file's own test class must stay in
// Piwigo\Tests\Integration for PSR-4 discovery.
namespace {
    if (! function_exists('l10n')) {
        function l10n(string $key, mixed ...$args): string
        {
            return $args === [] ? $key : vsprintf($key, array_map(static fn (mixed $a): string => is_scalar($a) ? (string) $a : '', $args));
        }
    }

    // pwg_activity() -- MaintenanceActionDispatcher now calls Piwigo\
    // Activity\ActivityService::record() directly (P23 batch 8d), so no
    // stub is needed; this Integration test's real DB connection exercises
    // genuine activity-logging behavior (the whole point of
    // test_a_real_action_is_logged_to_pwg_activity()) without a stub.

    // script_basename() stub removed -- ActivityService::record() now
    // calls Piwigo\Core\PageFilterHelper::scriptBasename() directly (P23
    // batch 8d), a real class method a same-named bare-function stub can
    // no longer intercept.
}

namespace Piwigo\Tests\Integration {

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Maintenance\MaintenanceActionDispatcher;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Url\UrlService;

/**
 * MaintenanceActionDispatcher::dispatch() -- the consolidated action
 * switch built during P23 batch 6h to replace the two independently-drifted
 * copies previously in admin/maintenance_actions.php and
 * admin/maintenance_env.php (see that class's own docblock for the 2 real
 * drift bugs found and fixed).
 *
 * Scoped to the actions reachable via pure Doctrine DBAL (already tested
 * at the repository layer by DbMaintenanceRepositoryTest -- this suite
 * covers the dispatcher's own routing, $page['infos'] messaging, and
 * pwg_activity() logging, not re-testing the repository's own SQL).
 * `empty_lounge`/`sessions`/`categories`/`images`/`database`/`c13y` all
 * need a legacy `$mysqli` (or `$logger`) global bootstrap this suite
 * doesn't set up -- no existing Integration test in this codebase does
 * either, so those are verified live instead (see the batch's own
 * completion notes for the curl round trip).
 */
final class MaintenanceActionDispatcherTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private MaintenanceActionDispatcher $dispatcher;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        Config::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->dispatcher = new MaintenanceActionDispatcher(new RedirectService(), new UrlService(new HtmlService()), new ConfigService($this->buildConfigRepository()));
    }

    public function test_search_purges_history_and_assigns_the_real_info_message(): void
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::search())
            ->values(['created_by' => ':createdBy'])
            ->setParameter('createdBy', 1)
            ->executeStatement();

        $this->dispatcher->dispatch('search');

        self::assertSame(0, $this->countRows(Tables::search()));
        // Regression guard for the pre-existing, already-fixed bug this
        // batch carried forward unchanged (see MaintenanceSubController's
        // own docblock): this message must be "Purge search history", not
        // a copy-paste of the 'c13y' case's "Reinitialize check integrity".
        self::assertContains(
            'Purge search history : action successfully performed.',
            \Piwigo\Core\PageState::current()->infos
        );
    }

    public function test_history_detail_and_summary_both_purge_via_one_dispatch_each(): void
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::history())
            ->values(['user_id' => ':userId'])
            ->setParameter('userId', 1)
            ->executeStatement();
        $this->conn->createQueryBuilder()
            ->insert(Tables::historySummary())
            ->values(['year' => ':year'])
            ->setParameter('year', 2026)
            ->executeStatement();

        $this->dispatcher->dispatch('history_detail');
        $this->dispatcher->dispatch('history_summary');

        self::assertSame(0, $this->countRows(Tables::history()));
        self::assertSame(0, $this->countRows(Tables::historySummary()));
    }

    public function test_a_real_action_is_logged_to_pwg_activity(): void
    {
        // Drift bug #1 fixed by this batch's consolidation: the original
        // admin/maintenance_env.php's own copy of this switch never called
        // pwg_activity() for any action -- the dispatcher always does now,
        // regardless of which tab reaches it.
        $before = $this->countRows(Tables::activity());

        $this->dispatcher->dispatch('search');

        self::assertSame($before + 1, $this->countRows(Tables::activity()));
        $lastAction = $this->conn->createQueryBuilder()
            ->select('action', 'object_id')
            ->from(Tables::activity())
            ->orderBy('activity_id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($lastAction);
        self::assertSame('maintenance', $lastAction['action']);
    }

    public function test_an_unregistered_action_is_not_logged_to_pwg_activity(): void
    {
        $before = $this->countRows(Tables::activity());

        $this->dispatcher->dispatch('this_action_does_not_exist');

        self::assertSame($before, $this->countRows(Tables::activity()));
    }

    private function countRows(string $table): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($table)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }
}

}
