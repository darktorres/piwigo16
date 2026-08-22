<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Override;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\UniqueExecLock;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Template\Renderer;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\LayoutStateTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
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
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), CurrentConfigTestFactory::get()));

        // footer.latte's {get_combined_scripts load='footer'} tag reaches
        // Template::urlService() -- unset by default, real
        // RequestBootstrap-only wiring this test never boots.

        $this->currentConfig()
            ->sendPiwigoInfos = false;

        // Deliberately NOT initialised here: CurrentTemplateTestFactory::get()->reset()/
        // Lang::reset() are the actual precondition the early-crash branch
        // needs, so each test below sets them up (or not) explicitly.
        CurrentTemplateTestFactory::get()->reset();
        LangTestFactory::get()->reset();
    }

    #[Override]
    protected function tearDown(): void
    {
        UniqueExecLock::ends(new Logger([
            'severity' => Logger::OFF,
        ]), 'check_for_updates');
        UniqueExecLock::reset();
        CurrentTemplateTestFactory::get()->reset();
        LangTestFactory::get()->reset();
        $this->currentConfig()
            ->reset();
        parent::tearDown();
    }

    private function currentConfig(): CurrentConfig
    {
        // Same "resolve the real container-shared instance" reasoning as
        // userService() just below.
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }

        return $currentConfig;
    }

    private function userService(): UserService
    {
        // Kernel is already booted by parent::setUp() -- resolve the same
        // container-shared instance a real request would get, matching
        // RedirectService's own real production callers. RedirectService
        // does not resolve UserService itself; the equivalent "Container
        // returned an unexpected type" check lives on whichever Bootstrap
        // resolver a real caller uses, e.g. RequestBootstrap::userService(),
        // already covered by that class's own tests.
        $userService = Kernel::container()->get(UserService::class);
        if (! $userService instanceof UserService) {
            throw new LogicException('Container returned an unexpected type for ' . UserService::class);
        }

        return $userService;
    }

    public function testRedirectHtmlBuildsAGuestUserAndAFreshTemplateWhenNeitherWasInitialisedYet(): void
    {
        // Preconditions for the early-crash branch: neither CurrentTemplate
        // nor Lang's own langInfo has been initialised (setUp() already
        // reset both) -- exactly what a fatal before common.inc.php
        // finishes bootstrapping would look like.
        self::assertFalse(CurrentTemplateTestFactory::get()->isInitialized());
        self::assertFalse(LangTestFactory::get()->isLangInfoInitialized());

        $execId = UniqueExecLock::begins(new Logger([
            'severity' => Logger::OFF,
        ]), 'check_for_updates');
        self::assertTrue($execId);

        $body = null;
        $status = null;
        try {
            new RedirectService(LangTestFactory::get(), $this->userService(), EventDispatcherTestFactory::get(), LayoutStateTestFactory::get(), new Renderer(CurrentTemplateTestFactory::get()))->redirectHtml('http://example.test/target.php', 'A custom redirect message');
        } catch (ResponseReadyException $e) {
            $response = $e->response();
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
        }

        self::assertSame(200, $status);
        // The early-crash branch really did build (and CurrentTemplateTestFactory::get()->set())
        // a fresh Template -- proven by isInitialized() now being true and
        // the rendered body containing content only a real compiled
        // header.latte/redirect.latte/footer.latte chain produces.
        self::assertTrue(CurrentTemplateTestFactory::get()->isInitialized());
        self::assertStringContainsString('A custom redirect message', $body);
        self::assertStringContainsString('href="http://example.test/target.php"', $body);
    }

    public function testRedirectHtmlDefaultsTheMessageToATranslatedRedirectionNoticeWhenMsgIsEmpty(): void
    {
        // Skip the early-crash branch entirely: a real Template + Lang
        // info already initialised, exactly like every other real request
        // reaching this class. Lang::setLangInfo() must run BEFORE
        // constructing Template -- its own constructor snapshots
        // Lang::langInfo() once into the 'lang_info' template var (see
        // Template::__construct()'s own body); setting it afterwards would
        // leave header.latte's `{$lang_info['code']}`/`{$lang_info['direction']}`
        // reads pointed at an empty array, triggering an "Undefined array
        // key" warning under this suite's own failOnWarning=true.
        LangTestFactory::get()->setLangInfo([
            'code' => 'en_UK',
            'direction' => 'ltr',
        ]);
        CurrentTemplateTestFactory::get()->set(TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes', 'default'));

        $execId = UniqueExecLock::begins(new Logger([
            'severity' => Logger::OFF,
        ]), 'check_for_updates');
        self::assertTrue($execId);

        $body = null;
        try {
            new RedirectService(LangTestFactory::get(), $this->userService(), EventDispatcherTestFactory::get(), LayoutStateTestFactory::get(), new Renderer(CurrentTemplateTestFactory::get()))->redirectHtml('http://example.test/other.php', '');
        } catch (ResponseReadyException $e) {
            $body = (string) $e->response()
                ->getBody();
        }

        self::assertStringContainsString(nl2br(LangTestFactory::get()->t('Redirection...')), $body);
    }

    public function testRedirectCallsRedirectHtmlWhenANonzeroRefreshTimeIsGiven(): void
    {
        // Ordering note: see test_redirectHtml_defaults_the_message_to_a_translated_redirection_notice_when_msg_is_empty()'s
        // own comment -- Lang::setLangInfo() must run before Template's
        // own construction snapshots it.
        LangTestFactory::get()->setLangInfo([
            'code' => 'en_UK',
            'direction' => 'ltr',
        ]);
        CurrentTemplateTestFactory::get()->set(TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes', 'default'));
        // Would take the redirectHttp() branch (a bare 302, no rendered
        // body) if $refresh_time were ignored -- forcing the http method
        // here proves it's genuinely $refresh_time, not
        // defaultRedirectMethod(), driving the else branch.
        $this->currentConfig()
            ->defaultRedirectMethod = 'http';

        $execId = UniqueExecLock::begins(new Logger([
            'severity' => Logger::OFF,
        ]), 'check_for_updates');
        self::assertTrue($execId);

        $status = null;
        $body = null;
        try {
            new RedirectService(LangTestFactory::get(), $this->userService(), EventDispatcherTestFactory::get(), LayoutStateTestFactory::get(), new Renderer(CurrentTemplateTestFactory::get()))->redirect('http://example.test/refresh-target.php', 'Refresh redirect', 5);
        } catch (ResponseReadyException $e) {
            $response = $e->response();
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
        }

        self::assertSame(200, $status);
        self::assertStringContainsString('Refresh redirect', $body);
        self::assertStringContainsString('content="5;url=http://example.test/refresh-target.php"', $body);
    }

    /**
     * P44-C: page_refresh['U_REFRESH'] used to reach both
     * layout.latte's meta-refresh `content` attribute and
     * redirect.latte's fallback `<a href>` via `|noescape` -- an
     * HTML-special-character-bearing URL (unvalidated real producers:
     * QSearchController's `$q`, BatchManagerSubController's `$getPage`)
     * reached both sites completely unescaped. Both sites now rely on
     * Latte's own auto-escape instead.
     */
    public function testRedirectHtmlEscapesAnHtmlSpecialCharacterBearingUrlAtBothPrintSites(): void
    {
        LangTestFactory::get()->setLangInfo([
            'code' => 'en_UK',
            'direction' => 'ltr',
        ]);
        CurrentTemplateTestFactory::get()->set(TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes', 'default'));

        $execId = UniqueExecLock::begins(new Logger([
            'severity' => Logger::OFF,
        ]), 'check_for_updates');
        self::assertTrue($execId);

        $maliciousUrl = 'http://example.test/target.php?q=x"><script>alert(1)</script>';

        $body = null;
        try {
            new RedirectService(LangTestFactory::get(), $this->userService(), EventDispatcherTestFactory::get(), LayoutStateTestFactory::get(), new Renderer(CurrentTemplateTestFactory::get()))->redirectHtml($maliciousUrl, 'A custom redirect message', 5);
        } catch (ResponseReadyException $e) {
            $body = (string) $e->response()
                ->getBody();
        }

        self::assertStringNotContainsString('"><script>alert(1)</script>', $body);
        self::assertStringContainsString('content="5;url=http://example.test/target.php?q=x&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $body);
        self::assertStringContainsString('href="http://example.test/target.php?q=x&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $body);
    }
}
