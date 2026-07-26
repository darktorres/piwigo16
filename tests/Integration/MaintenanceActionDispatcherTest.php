<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Maintenance\MaintenanceActionDispatcher;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Kernel;
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

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        // DI-phase follow-on: MaintenanceActionDispatcher now resolves
        // DbMaintenanceRepository via Bootstrap\AdminAccessor ->
        // Kernel::container(), which this isolated Integration test (no
        // full RequestBootstrap) wouldn't otherwise boot.
        Kernel::boot();

        $this->conn = DbConnection::build();
        $this->dispatcher = new MaintenanceActionDispatcher(new RedirectService(), new UrlService(new HtmlService()), new ConfigService($this->buildConfigRepository()));
    }

    #[\Override]
    protected function tearDown(): void
    {
        Kernel::reset();
        parent::tearDown();
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
