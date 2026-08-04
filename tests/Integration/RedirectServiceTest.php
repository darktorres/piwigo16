<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Bootstrap\RedirectService;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Lang;
use Piwigo\Core\UniqueExecLock;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\ScriptLoader;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;
use Piwigo\Users\UserService;

/**
 * Piwigo\Bootstrap\RedirectService::redirectHtml()/redirect() -- the two
 * plain http-redirect Unit tests (tests/Unit/Bootstrap/RedirectServiceTest.php)
 * cover redirectHttp() entirely, but redirectHtml()'s own body needs a
 * real Template/UserService/DB, so the remaining red lines land here:
 *
 *  - the "no $template/$lang_info yet" early-crash fallback branch (a
 *    guest User + a fresh Template built from scratch) -- reachable only
 *    when CurrentTemplate/Lang genuinely haven't been initialised yet,
 *    which every other real Integration/Browser test that reaches this
 *    class always has been by that point.
 *  - the empty-$msg default-text branch (nl2br(Lang::t('Redirection...'))),
 *    as opposed to the caller-supplied-$msg branch already covered
 *    elsewhere.
 *  - redirect()'s own `else` branch calling redirectHtml() (a non-zero
 *    $refresh_time forces it regardless of defaultRedirectMethod()).
 *
 * Every scenario ends inside PageTail::renderToString() (redirectHtml()'s
 * own tail call) -- same 2 skip-listed-class avoidance techniques as
 * PageTailTest.php's own docblock: winning the real 'check_for_updates'
 * lock first (so checkForUpdates() loses the race instead of reaching
 * Admin\Extensions\CoreUpdateService), and
 * CurrentConfig::setSendPiwigoInfos(false) (so Admin\PiwigoInfosSender::send()
 * no-ops at its own very first guard) -- neither skip-listed class is
 * ever actually constructed here.
 */
final class RedirectServiceTest extends IntegrationTestCase
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
        // Kernel is already booted by parent::setUp() with this exact same
        // dirname(__DIR__, 2) root -- no need to boot (or bind Paths) again.
        CurrentConfigService::current()->set(new ConfigService($this->buildConfigRepository(), new \Piwigo\PluginConfig\EventDispatcher()));

        // footer.tpl's {get_combined_scripts load='footer'} tag reaches
        // ScriptLoader::urlService() -- unset by default, real
        // RequestBootstrap-only wiring this test never boots.

        CurrentConfig::setSendPiwigoInfos(false);

        // Deliberately NOT initialised here: CurrentTemplate::current()->reset()/
        // Lang::reset() are the actual precondition the early-crash branch
        // needs, so each test below sets them up (or not) explicitly.
        CurrentTemplate::current()->reset();
        Lang::current()->reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        UniqueExecLock::ends('check_for_updates');
        CurrentTemplate::current()->reset();
        Lang::current()->reset();
        CurrentConfig::reset();
        parent::tearDown();
    }

    private function userService(): UserService
    {
        // Kernel is already booted by parent::setUp() -- resolve the same
        // container-shared instance a real request would get, matching
        // RedirectService's own real production callers (singleton/
        // service-locator elimination campaign, Phase 6). RedirectService
        // no longer resolves UserService itself (that was the deleted
        // `userService()` private static resolver this class used to have,
        // along with its own "Container returned an unexpected type"
        // \LogicException -- the equivalent check now lives on whichever
        // Bootstrap resolver a real caller uses, e.g.
        // RequestBootstrap::userService(), already covered by that class's
        // own tests).
        $userService = \Piwigo\Core\Kernel::container()->get(UserService::class);
        if (! $userService instanceof UserService) {
            throw new \LogicException('Container returned an unexpected type for ' . UserService::class);
        }

        return $userService;
    }

    public function test_redirectHtml_builds_a_guest_user_and_a_fresh_template_when_neither_was_initialised_yet(): void
    {
        // Preconditions for the early-crash branch: neither CurrentTemplate
        // nor Lang's own langInfo has been initialised (setUp() already
        // reset both) -- exactly what a fatal before common.inc.php
        // finishes bootstrapping would look like.
        self::assertFalse(CurrentTemplate::current()->isInitialized());
        self::assertFalse(Lang::current()->isLangInfoInitialized());

        $execId = UniqueExecLock::begins('check_for_updates');
        self::assertIsString($execId);

        $body = null;
        $status = null;
        try {
            new RedirectService(Lang::current(), $this->userService())->redirectHtml('http://example.test/target.php', 'A custom redirect message');
        } catch (ResponseReadyException $e) {
            $response = $e->response();
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
        }

        self::assertSame(200, $status);
        // The early-crash branch really did build (and CurrentTemplate::current()->set())
        // a fresh Template -- proven by isInitialized() now being true and
        // the rendered body containing content only a real compiled
        // header.tpl/redirect.tpl/footer.tpl chain produces.
        self::assertTrue(CurrentTemplate::current()->isInitialized());
        self::assertStringContainsString('A custom redirect message', $body);
        self::assertStringContainsString('href="http://example.test/target.php"', $body);
    }

    public function test_redirectHtml_defaults_the_message_to_a_translated_redirection_notice_when_msg_is_empty(): void
    {
        // Skip the early-crash branch entirely: a real Template + Lang
        // info already initialised, exactly like every other real request
        // reaching this class. Lang::setLangInfo() must run BEFORE
        // constructing Template -- its own constructor snapshots
        // Lang::langInfo() once into Smarty's 'lang_info' var (see
        // Template::__construct()'s own body); setting it afterwards would
        // leave header.tpl's `{$lang_info.code}`/`{$lang_info.direction}`
        // reads pointed at an empty array (confirmed live: a real
        // "Undefined array key" warning under this suite's own
        // failOnWarning=true).
        Lang::current()->setLangInfo(['code' => 'en_UK', 'direction' => 'ltr']);
        CurrentTemplate::current()->set(new Template(CurrentPaths::get()->root . 'themes', 'default'));

        $execId = UniqueExecLock::begins('check_for_updates');
        self::assertIsString($execId);

        $body = null;
        try {
            new RedirectService(Lang::current(), $this->userService())->redirectHtml('http://example.test/other.php', '');
        } catch (ResponseReadyException $e) {
            $body = (string) $e->response()->getBody();
        }

        self::assertStringContainsString(nl2br(Lang::current()->t('Redirection...')), $body);
    }

    public function test_redirect_calls_redirectHtml_when_a_nonzero_refresh_time_is_given(): void
    {
        // Ordering note: see test_redirectHtml_defaults_the_message_to_a_translated_redirection_notice_when_msg_is_empty()'s
        // own comment -- Lang::setLangInfo() must run before Template's
        // own construction snapshots it.
        Lang::current()->setLangInfo(['code' => 'en_UK', 'direction' => 'ltr']);
        CurrentTemplate::current()->set(new Template(CurrentPaths::get()->root . 'themes', 'default'));
        // Would take the redirectHttp() branch (a bare 302, no rendered
        // body) if $refresh_time were ignored -- forcing the http method
        // here proves it's genuinely $refresh_time, not
        // defaultRedirectMethod(), driving the else branch.
        CurrentConfig::setDefaultRedirectMethod('http');

        $execId = UniqueExecLock::begins('check_for_updates');
        self::assertIsString($execId);

        $status = null;
        $body = null;
        try {
            new RedirectService(Lang::current(), $this->userService())->redirect('http://example.test/refresh-target.php', 'Refresh redirect', 5);
        } catch (ResponseReadyException $e) {
            $response = $e->response();
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
        }

        self::assertSame(200, $status);
        self::assertStringContainsString('Refresh redirect', $body);
        self::assertStringContainsString('content="5;url=http://example.test/refresh-target.php"', $body);
    }
}
