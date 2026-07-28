<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Bootstrap\PageTail;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\AppInfo;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\UniqueExecLock;
use Piwigo\Html\HtmlService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\ScriptLoader;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;

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
 * UniqueExecLockTest::test_begins_loses_the_race_when_another_exec_already_holds_a_fresh_lock()
 * already proves the mechanism works: winning the real 'check_for_updates'
 * lock *before* calling PageTail::renderToString() makes checkForUpdates()'s
 * own UniqueExecLock::begins() call lose the race and return early,
 * never reaching CoreUpdateService at all.
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

        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        Kernel::boot();
        CurrentConfigService::set(new ConfigService($this->buildConfigRepository()));

        CurrentPaths::set(Paths::fromRoot(dirname(__DIR__, 2)));
        // footer.tpl's own {get_combined_scripts load='footer'} tag reaches
        // ScriptLoader::urlService() -- unset by default, real
        // RequestBootstrap-only wiring this test never boots.
        ScriptLoader::setUrlService(new UrlService(new HtmlService()));
        CurrentTemplate::set(new Template(CurrentPaths::get()->root . 'themes', 'default'));

        CurrentConfig::setSendPiwigoInfos(false);
        CurrentConfig::setShowVersion(true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        UniqueExecLock::ends('check_for_updates');
        CurrentTemplate::reset();
        CurrentConfig::reset();
        Kernel::reset();
        parent::tearDown();
    }

    public function test_renderToString_skips_the_update_check_when_another_exec_already_holds_the_fresh_lock(): void
    {
        CurrentConfig::setUpdateNotifyCheckPeriod(1);
        // Far enough in the past that, with a 1-second check period,
        // strtotime($lastCheck) < strtotime('1 seconds ago') is true --
        // reaches the `$check_for_updates = true;` line under test.
        CurrentConfig::setUpdateNotifyLastCheck('2020-01-01 00:00:00');

        // Win the real lock first, under a *different* random exec id than
        // whatever checkForUpdates() will generate internally -- its own
        // begins() call then loses the race.
        $execId = UniqueExecLock::begins('check_for_updates');
        self::assertIsString($execId);
        self::assertTrue(UniqueExecLock::isRunning('check_for_updates'));

        $output = PageTail::renderToString();

        // Proves renderToString() completed the whole real render (not
        // just the update-check branch) without ever touching the network:
        // AppInfo::URL is the footer.tpl "Powered by" link href, only
        // present once Smarty has actually compiled and rendered the real
        // theme template end to end.
        self::assertStringContainsString('href="' . AppInfo::URL . '"', $output);
        self::assertStringContainsString(AppInfo::VERSION, $output);

        // The lock this test itself won is still the one present --
        // checkForUpdates()'s own begins() call really did lose the race
        // rather than clearing and replacing it.
        self::assertTrue(UniqueExecLock::isRunning('check_for_updates'));
    }
}
