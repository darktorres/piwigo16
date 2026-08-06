<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\TemplateTestFactory;
use LogicException;
use Piwigo\Db\DbConnection;
use Piwigo\Tests\Support\DbCredentialsTestFactory;
use Piwigo\Db\AdvisorySessionLock;
use Piwigo\Bootstrap\PageTail;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Core\AppInfo;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Core\Logger;
use Piwigo\Core\Kernel;
use Piwigo\Core\UniqueExecLock;
use Piwigo\Template\CurrentTemplate;

/**
 * Piwigo\Bootstrap\PageTail -- renderToString()/render() themselves are
 * exercised indirectly by every Browser suite page-load test (every real
 * Controller calls one of them), so the only red line is inside
 * checkForUpdates()'s own "the last check is old enough to recheck" branch
 * (`$check_for_updates = true;` reached through the non-null
 * `$update_notify_last_check` path, as opposed to the adjacent null-check
 * path already covered elsewhere) -- deliberately never previously
 * exercised together with a *stale* last-check value.
 *
 * Reaching that branch would normally go on to construct
 * Piwigo\Admin\Extensions\CoreUpdateService (this project's own documented
 * skip-list class -- static HttpClientService::fetch() to piwigo.org, no
 * fake-able seam) -- avoided here the same way
 * UniqueExecLockTest::test_begins_fails_when_a_different_connection_already_holds_the_lock()
 * already proves the mechanism works: winning the real 'check_for_updates'
 * `GET_LOCK()` on a *separate* connection before calling
 * PageTail::renderToString() makes checkForUpdates()'s own
 * UniqueExecLock::begins() call (its own cached, request-scoped
 * connection) lose the race and return early, never reaching
 * CoreUpdateService at all. Must be a genuinely separate connection. not
 * a 2nd begins() call through UniqueExecLock itself -- GET_LOCK()
 * re-acquiring an already-held name on the SAME connection just succeeds
 * again (reentrant), it wouldn't simulate contention at all.
 *
 * The renderer's own telemetry send (Piwigo\Admin\PiwigoInfosSender,
 * also skip-listed) is avoided the same way a real "send_piwigo_infos"-
 * disabled installation would skip it: CurrentConfig::setSendPiwigoInfos(false)
 * short-circuits at that class's own very first guard, before it does
 * anything else (confirmed by reading PiwigoInfosSender::send()'s body).
 */
final class PageTailTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        // Kernel is already booted by parent::setUp() with this exact same
        // dirname(__DIR__, 2) root -- no need to boot (or bind Paths) again.
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfig::current()));

        // footer.tpl's own {get_combined_scripts load='footer'} tag reaches
        // ScriptLoader::urlService() -- unset by default, real
        // RequestBootstrap-only wiring this test never boots.
        CurrentTemplate::current()->set(TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes', 'default'));

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->setSendPiwigoInfos(false);
        $currentConfig->setShowVersion(true);
    }

    #[Override]
    protected function tearDown(): void
    {
        UniqueExecLock::ends(new Logger(['severity' => Logger::OFF]), 'check_for_updates');
        UniqueExecLock::reset();
        CurrentTemplate::current()->reset();
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        Kernel::reset();
        parent::tearDown();
    }

    public function test_renderToString_skips_the_update_check_when_another_exec_already_holds_the_fresh_lock(): void
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->setUpdateNotifyCheckPeriod(1);
        // Far enough in the past that, with a 1-second check period,
        // strtotime($lastCheck) < strtotime('1 seconds ago') is true --
        // reaches the `$check_for_updates = true;` line under test.
        $currentConfig->setUpdateNotifyLastCheck('2020-01-01 00:00:00');

        // Win the real lock first, on a genuinely separate connection --
        // GET_LOCK() re-acquiring an already-held name on the SAME
        // connection just succeeds again (reentrant), so calling
        // UniqueExecLock::begins() a 2nd time here wouldn't simulate real
        // contention at all. checkForUpdates()'s own begins() call (its
        // own cached, request-scoped connection) then loses the race.
        $otherConn = DbConnection::build();
        $lockName = 'piwigo_exec_' . sha1(DbCredentialsTestFactory::get()->prefix . ':unique_exec:check_for_updates');
        self::assertTrue(AdvisorySessionLock::acquire($otherConn, $lockName, 1));
        self::assertTrue(UniqueExecLock::isRunning('check_for_updates'));

        try {
            $output = PageTail::renderToString();

            // Proves renderToString() completed the whole real render (not
            // just the update-check branch) without ever touching the
            // network: AppInfo::URL is the footer.tpl "Powered by" link
            // href, only present once Smarty has actually compiled and
            // rendered the real theme template end to end.
            self::assertStringContainsString('href="' . AppInfo::URL . '"', $output);
            self::assertStringContainsString(AppInfo::VERSION, $output);

            // The lock this test itself won is still the one present --
            // checkForUpdates()'s own begins() call really did lose the
            // race rather than clearing and replacing it.
            self::assertTrue(UniqueExecLock::isRunning('check_for_updates'));
        } finally {
            AdvisorySessionLock::release($otherConn, $lockName);
        }
    }
}
