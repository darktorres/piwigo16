<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Event\Picture\GetElementUrl;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseReadyException;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\ScriptLoader;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Bootstrap\RequestBootstrap::finalize() -- Phase 3 of the HTTP
 * boot sequence. Never called directly by any existing Unit/Integration
 * test (only reachable, until now, through the Browser suite's real HTTP
 * requests via bootEntryPoint() -> connect() -> finalize()); safe to call
 * standalone here since, unlike connect(), it does no session-bootstrap/
 * plugin-loading/global-error-handler-installation work -- confirmed by
 * reading its own body -- so every precondition it needs (a real
 * CurrentUser, CurrentConfigService, DB connection) can be set up by hand,
 * the same "call the phase directly with hand-built preconditions" shape
 * as this project's own phase-splitting docblocks describe.
 *
 * Covers 6 branches the real Browser suite's fixture state never
 * naturally exercises together:
 *  - the stale-auth-key error message.
 *  - the mobile-theme CurrentConfig::mobilTheme() override.
 *  - the "first time noPhotoYet() is null" NoPhotoYetRenderer::render()
 *    call site (the fixture's 5 images mean NoPhotoYetRenderer's own
 *    guard-false path runs -- never its exit()-terminated nb_photos===0
 *    branch, matching NoPhotoYetRendererTest's own documented boundary).
 *  - the guest_must_be_guest header warning.
 *  - the gallery-locked 503 response.
 *  - the originalUrlProtection event-handler registration.
 */
final class RequestBootstrapFinalizeTest extends IntegrationTestCase
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

        // Kernel is already booted by parent::setUp() with this exact same
        // dirname(__DIR__, 2) root -- no need to boot (or bind Paths) again.
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        CurrentConfigService::set(new ConfigService($this->buildConfigRepository(), new \Piwigo\PluginConfig\EventDispatcher()));
        // footer.tpl (reached via CurrentTemplate's parse()) needs this --
        // same as PageTailTest/RedirectServiceTest's own identical setup.
        ScriptLoader::setUrlService(new UrlService(new HtmlService()));

        CurrentUser::set(new User(
            id: UserId::from(3),
            username: 'regular_user',
            email: 'regular@example.test',
            language: 'en_UK',
            theme: 'default',
            status: UserStatus::Normal,
            enabledHigh: true,
        ));
    }

    #[\Override]
    protected function tearDown(): void
    {
        CurrentUser::reset();
        CurrentTemplate::reset();
        EventDispatcher::get()->reset();
        PageState::current()->reset();
        CurrentConfig::reset();
        parent::tearDown();
    }

    public function test_finalize_adds_a_stale_auth_key_error_message(): void
    {
        PageState::current()->markAuthKeyInvalid();

        RequestBootstrap::finalize();

        self::assertSame(
            [Lang::t('Your authentication key is no longer valid.') . sprintf(
                ' <a href="%s">%s</a>',
                new UrlService(new HtmlService())->getRootUrl() . 'identification.php',
                Lang::t('Login')
            )],
            PageState::current()->errors
        );
    }

    public function test_finalize_uses_the_mobile_theme_when_the_session_mobile_theme_flag_is_set(): void
    {
        CurrentConfig::setMobilTheme('mobile-theme-name');
        $_SESSION['pwg_mobile_theme'] = true;

        try {
            RequestBootstrap::finalize();

            self::assertTrue(CurrentTemplate::isInitialized());
            self::assertStringContainsString(
                dirname(__DIR__, 2) . '/themes/mobile-theme-name/template',
                CurrentTemplate::get()->get_template_dir()
            );
        } finally {
            unset($_SESSION['pwg_mobile_theme']);
        }
    }

    public function test_finalize_calls_noPhotoYetRenderer_when_noPhotoYet_config_is_unset(): void
    {
        // The real fixture DOES seed a 'no_photo_yet' row ("false") --
        // real production connect() would call ConfigService::
        // loadConfFromDb() first and never see this branch with this
        // fixture. setUp() deliberately never calls loadConfFromDb() at
        // all, so CurrentConfig::$noPhotoYet stays at its own class
        // property default (null) -- the honest equivalent of a genuinely
        // fresh install where this key has never been written yet.
        self::assertNull(CurrentConfig::noPhotoYet());

        // regular_user is neither guest nor admin, so
        // NoPhotoYetRenderer::render()'s own outer guard is false and it
        // returns immediately without querying the images table at all --
        // this proves finalize()'s own call site runs without throwing,
        // which is what its 2 red lines needed; NoPhotoYetRenderer's own
        // internal branches are NoPhotoYetRendererTest.php's job.
        RequestBootstrap::finalize();

        self::assertNull(CurrentConfig::noPhotoYet());
    }

    public function test_finalize_adds_a_header_warning_when_guest_must_be_guest_is_flagged(): void
    {
        CurrentUser::set(new User(
            id: UserId::from(2),
            username: 'guest',
            email: '',
            language: 'en_UK',
            theme: 'default',
            status: UserStatus::Guest,
            enabledHigh: false,
            internalStatus: ['guest_must_be_guest' => true],
        ));

        RequestBootstrap::finalize();

        // finalize() itself flushes PageState::current()->headerMessages
        // into the template's own 'header_msgs' var and resets the
        // PageState-side list back to [] in the same method body -- the
        // template var is the only place left to observe it afterwards.
        self::assertSame(
            [Lang::t('Bad status for user "guest", default status will be used. Please notify the webmaster.')],
            CurrentTemplate::get()->get_template_vars('header_msgs')
        );
    }

    public function test_finalize_throws_a_503_when_the_gallery_is_locked_for_a_non_admin_non_identification_request(): void
    {
        CurrentConfig::setGalleryLocked(true);

        try {
            RequestBootstrap::finalize();
            self::fail('finalize() should have thrown ResponseReadyException.');
        } catch (ResponseReadyException $e) {
            $response = $e->response();
            self::assertSame(503, $response->getStatusCode());
            self::assertSame('900', $response->getHeaderLine('Retry-After'));
            $expectedBody = '<a href="' . new UrlService(new HtmlService())->getAbsoluteRootUrl(false) . 'identification.php">'
                . Lang::t('The gallery is locked for maintenance. Please, come back later.') . '</a>'
                . str_repeat(' ', 512);
            self::assertSame($expectedBody, (string) $response->getBody());
        }
    }

    public function test_finalize_registers_the_url_protection_handlers_when_originalUrlProtection_is_configured(): void
    {
        CurrentConfig::setOriginalUrlProtection('all');

        RequestBootstrap::finalize();

        $result = EventDispatcher::get()->dispatchChange(new GetElementUrl(
            'http://original.example/x.jpg',
            ['id' => 42, 'path' => 'x.jpg']
        ));

        self::assertSame(
            new UrlService(new HtmlService())->getActionUrl(42, 'e', false),
            $result->url
        );
    }
}
