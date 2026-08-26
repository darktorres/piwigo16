<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Override;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Picture\Event\GetElementUrl;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\LayoutStateTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Bootstrap\RequestBootstrap::finalize() -- since workstream C3
 * Phase 1, only the still-legacy, Template-dependent remainder of the
 * original method (theme resolution, `Template` construction,
 * `NoPhotoYetRenderer`, the gallery-locked 503 check, default
 * event-handler registrations), called by `Bootstrap\
 * FinalizeBridgeMiddleware` as the last step of the real bootstrap-phase
 * middleware chain, not called directly by `bootEntryPoint()` anymore.
 * Every precondition it needs (a real CurrentUser, CurrentConfigService,
 * DB connection) is set up by hand in this test, the same "call the phase
 * directly with hand-built preconditions" contract this file always used.
 *
 * The stale-auth-key error message this file used to cover moved with
 * the rest of language loading to `Http\Middleware\LanguageMiddleware`,
 * earlier in the pipeline -- see `LanguageMiddlewareTest` for that
 * coverage now.
 *
 * Covers 5 branches the real Browser suite's fixture state never
 * naturally exercises together:
 *  - the mobile-theme CurrentConfig::mobileTheme() override.
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

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();

        // Kernel is already booted by parent::setUp() with this exact same
        // dirname(__DIR__, 2) root -- no need to boot (or bind Paths) again.
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), CurrentConfigTestFactory::get()));
        // footer.latte (reached via CurrentTemplate's parse()) needs this --
        // same as PageTailTest/RedirectServiceTest's own identical setup.

        CurrentUserTestFactory::get()->set(new User(
            id: UserId::from(3),
            username: Username::from('regular_user'),
            email: Email::from('regular@example.test'),
            language: LangCode::from('en_UK'),
            theme: ThemeId::from('default'),
            status: UserStatus::Normal,
            enabledHigh: true,
        ));
    }

    #[Override]
    protected function tearDown(): void
    {
        CurrentUserTestFactory::get()->reset();
        CurrentTemplateTestFactory::get()->reset();
        EventDispatcherTestFactory::get()->reset();
        PageStateTestFactory::get()->reset();
        LayoutStateTestFactory::get()->reset();
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testFinalizeUsesTheMobileThemeWhenTheSessionMobileThemeFlagIsSet(): void
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->mobileTheme = 'mobile-theme-name';
        $_SESSION['pwg_mobile_theme'] = true;

        try {
            RequestBootstrap::finalize();

            self::assertTrue(CurrentTemplateTestFactory::get()->isInitialized());
            self::assertStringContainsString(
                dirname(__DIR__, 2) . '/themes/mobile-theme-name/template',
                CurrentTemplateTestFactory::get()->get()->getTemplateDir()
            );
        } finally {
            unset($_SESSION['pwg_mobile_theme']);
        }
    }

    public function testFinalizeCallsNoPhotoYetRendererWhenNoPhotoYetConfigIsUnset(): void
    {
        // The real fixture DOES seed a 'no_photo_yet' row ("false") --
        // real production connect() would call ConfigService::
        // loadConfFromDb() first and never see this branch with this
        // fixture. setUp() deliberately never calls loadConfFromDb() at
        // all, so CurrentConfig::$noPhotoYet stays at its own class
        // property default (null) -- the honest equivalent of a genuinely
        // fresh install where this key has never been written yet.
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        self::assertNull($currentConfig->noPhotoYet);

        // regular_user is neither guest nor admin, so
        // NoPhotoYetRenderer::render()'s own outer guard is false and it
        // returns immediately without querying the images table at all --
        // this proves finalize()'s own call site runs without throwing,
        // which is what its 2 red lines needed; NoPhotoYetRenderer's own
        // internal branches are NoPhotoYetRendererTest.php's job.
        RequestBootstrap::finalize();

        self::assertNull($currentConfig->noPhotoYet);
    }

    public function testFinalizeAddsAHeaderWarningWhenGuestMustBeGuestIsFlagged(): void
    {
        CurrentUserTestFactory::get()->set(new User(
            id: UserId::from(2),
            username: Username::from('guest'),
            email: null,
            language: LangCode::from('en_UK'),
            theme: ThemeId::from('default'),
            status: UserStatus::Guest,
            enabledHigh: false,
            internalStatus: [
                'guest_must_be_guest' => true,
            ],
        ));

        RequestBootstrap::finalize();

        // finalize() itself flushes LayoutStateTestFactory::get()->headerMessages
        // into the template's own 'header_msgs' var and resets the
        // LayoutState-side list back to [] in the same method body -- the
        // template var is the only place left to observe it afterwards.
        self::assertSame(
            [LangTestFactory::get()->t('Bad status for user "guest", using default status. Please notify the webmaster.')],
            CurrentTemplateTestFactory::get()->get()->getTemplateVars('header_msgs')
        );
    }

    public function testFinalizeThrowsA503WhenTheGalleryIsLockedForANonAdminNonIdentificationRequest(): void
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->galleryLocked = true;

        try {
            RequestBootstrap::finalize();
            self::fail('finalize() should have thrown ResponseReadyException.');
        } catch (ResponseReadyException $e) {
            $response = $e->response();
            self::assertSame(503, $response->getStatusCode());
            self::assertSame('900', $response->getHeaderLine('Retry-After'));
            $expectedBody = '<a href="' . UrlServiceTestFactory::build()->getAbsoluteRootUrl(false) . 'identification.php">'
                . LangTestFactory::get()->t('The gallery is locked for maintenance. Please, come back later.') . '</a>'
                . str_repeat(' ', 512);
            self::assertSame($expectedBody, (string) $response->getBody());
        }
    }

    public function testFinalizeRegistersTheUrlProtectionHandlersWhenOriginalUrlProtectionIsConfigured(): void
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->originalUrlProtection = 'all';

        RequestBootstrap::finalize();

        $result = EventDispatcherTestFactory::get()->dispatch(new GetElementUrl(
            'http://original.example/x.jpg',
            [
                'id' => 42,
                'path' => 'x.jpg',
            ]
        ));

        self::assertSame(
            UrlServiceTestFactory::build()->getActionUrl(42, 'e', false),
            $result->url
        );
    }
}
