<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Caddie\CaddieRepository;
use Piwigo\Category\CategoryRepository;
use Piwigo\Common\ValueObject\PluginId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AdminContext;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\ThemeRepository;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Mail\BeforeSendMail;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Image\ImageRepository;
use Piwigo\Mail\MailService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\ExtensionContext;
use Piwigo\PluginConfig\ExtensionInterface;
use Piwigo\PluginConfig\Facade\BasicThemeInfo;
use Piwigo\PluginConfig\Facade\BasicUserInfo;
use Piwigo\PluginConfig\Facade\ImageReadFacade;
use Piwigo\PluginConfig\Facade\ThemeReadFacade;
use Piwigo\PluginConfig\Facade\UserReadFacade;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Users\UserStatus;
use RuntimeException;
use Symfony\Component\Mime\Email;

/**
 * Test-only typed event -- ExtensionContext::dispatchChange()/
 * dispatchNotify() are thin passthroughs to EventDispatcher's own
 * already-independently-tested dispatch logic (EventDispatcherTest.php),
 * so this only needs to prove the passthrough itself reaches a real
 * registered handler, not re-verify dispatch semantics.
 */
final class ExtensionContextTestFakeEvent
{
    public bool $touched = false;
}

/**
 * Minimal ExtensionInterface implementor -- only boot() is exercised
 * here (against a real ExtensionContext); every other method already has
 * its own dedicated, DB-free coverage in ExtensionInterfaceTest.php
 * (Unit tier).
 */
final class ExtensionContextTestFakePlugin implements ExtensionInterface
{
    public ?ExtensionContext $receivedContext = null;

    public function boot(ExtensionContext $context): void
    {
        $this->receivedContext = $context;
    }

    public function install(): void {}

    public function activate(): void {}

    public function deactivate(): void {}

    public function uninstall(): void {}

    public function update(string $oldVersion, string $newVersion): void {}

    public function subscribedEvents(): array
    {
        return [];
    }
}

/**
 * Covers ExtensionContext end-to-end against a real container/DB --
 * unlike Listener\* (P27.0), this class is composed almost entirely of
 * thin passthroughs to already-independently-tested collaborators, so one
 * Integration file covers every accessor instead of splitting a DB-
 * touching tier from a pure-Unit one: setLanguage()/images() genuinely
 * need a live DB, and the remaining accessors are cheap enough here that
 * a second, separately-wired Unit file would just re-derive the same
 * construction for no real isolation benefit.
 */
final class ExtensionContextTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private ImageReadFacade $imageReadFacade;

    private ExtensionContext $context;

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

        $this->conn = DbConnection::build();

        // mail() (P27.13) reaches MailService::mail(), which internally
        // constructs a Template -- its constructor needs a populated
        // CurrentConfigService, which IntegrationTestCase::tearDown()
        // resets after every test. Wired here the same way
        // MailGoldenHtmlSnapshotTest.php's own setUp() does.
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        $configRepo = EntityManagerFactory::build($this->conn)->getRepository(ConfigEntry::class);
        $configService = new ConfigService($configRepo, new EventDispatcher(), CurrentConfigTestFactory::get());
        CurrentConfigServiceTestFactory::get()->set($configService);
        $configService->loadConfFromDb();

        $currentUser = Kernel::container()->get(CurrentUser::class);
        if (! $currentUser instanceof CurrentUser) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentUser::class);
        }
        $currentUser->attachGlobals();

        $this->imageReadFacade = new ImageReadFacade(
            $this->containerGet(CaddieRepository::class),
            $this->containerGet(ImageRepository::class),
            $this->containerGet(CategoryRepository::class),
        );

        $this->context = $this->buildContext(PluginId::from('test-plugin'));
    }

    private function buildContext(PluginId $extensionId, ?AdminContext $adminContext = null): ExtensionContext
    {
        $currentUser = Kernel::container()->get(CurrentUser::class);
        if (! $currentUser instanceof CurrentUser) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentUser::class);
        }

        $userService = Kernel::container()->get(UserService::class);
        if (! $userService instanceof UserService) {
            throw new LogicException('Container returned an unexpected type for ' . UserService::class);
        }

        return new ExtensionContext(
            $extensionId,
            $this->containerGet(CurrentTemplate::class),
            $this->containerGet(CurrentConfig::class),
            $currentUser,
            $userService,
            $this->containerGet(Lang::class),
            $this->containerGet(UrlServiceInterface::class),
            $this->containerGet(RedirectServiceInterface::class),
            $adminContext ?? $this->containerGet(AdminContext::class),
            $this->containerGet(EventDispatcher::class),
            $this->containerGet(SessionService::class),
            $this->imageReadFacade,
            $this->containerGet(Paths::class),
            $this->containerGet(ConfigService::class),
            $this->containerGet(EntityManagerInterface::class),
            $this->containerGet(MailService::class),
            new UserReadFacade($this->containerGet(UserRepository::class)),
            new ThemeReadFacade($this->containerGet(ThemeRepository::class)),
            $this->containerGet(CsrfService::class),
            $this->containerGet(HtmlRenderingInterface::class),
            $this->containerGet(AccessControl::class),
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function containerGet(string $class): object
    {
        $instance = Kernel::container()->get($class);
        if (! $instance instanceof $class) {
            throw new LogicException('Container returned an unexpected type for ' . $class);
        }

        return $instance;
    }

    public function testSetLanguagePersistsToUserInfosAndSyncsCurrentUser(): void
    {
        // testUpdateLanguagePersistsTheNewValue() in AuthRepositoryTest.php
        // uses the identical fixture row (user_id 1) + reset-to-en_UK
        // pattern for the same reason: shared fixture state other tests
        // depend on.
        $this->impersonate(1);

        $this->context->setLanguage('fr_FR');

        $persisted = $this->conn->createQueryBuilder()
            ->select('language')
            ->from('user_infos')
            ->where('user_id = :id')
            ->setParameter('id', 1)
            ->executeQuery()
            ->fetchOne();

        self::assertSame('fr_FR', $persisted);
        self::assertSame('fr_FR', $this->context->currentUser()->language->value);

        $this->conn->executeStatement("UPDATE user_infos SET language = 'en_UK' WHERE user_id = 1");
    }

    /**
     * syncLanguageForRequest()'s whole point (P27.6, language_switch's own
     * guest/generic path -- no individual user_infos row to persist a
     * session-only visitor's language choice to) is the mirror image of
     * setLanguage()'s own test above: the in-memory CurrentUser side takes
     * effect, but user_infos itself never gets touched.
     */
    public function testSyncLanguageForRequestUpdatesCurrentUserWithoutPersisting(): void
    {
        $this->impersonate(1);

        $before = $this->conn->createQueryBuilder()
            ->select('language')
            ->from('user_infos')
            ->where('user_id = :id')
            ->setParameter('id', 1)
            ->executeQuery()
            ->fetchOne();

        $this->context->syncLanguageForRequest('fr_FR');

        $after = $this->conn->createQueryBuilder()
            ->select('language')
            ->from('user_infos')
            ->where('user_id = :id')
            ->setParameter('id', 1)
            ->executeQuery()
            ->fetchOne();

        self::assertSame('fr_FR', $this->context->currentUser()->language->value);
        self::assertSame($before, $after);
    }

    public function testLanguagesReturnsEveryInstalledLanguageKeyedByCode(): void
    {
        $languages = $this->context->languages();

        self::assertArrayHasKey('en_UK', $languages);
        self::assertNotSame('', $languages['en_UK']);
    }

    /**
     * getSetting()/setSetting() (P27.6, `elegant`'s own real
     * admin/upgrade.inc.php self-healing config check +
     * `modus`'s own theme_activate() need) round-trip against the real
     * config table by an arbitrary, unnamed key -- not one of
     * CurrentConfig's own named properties.
     */
    public function testGetSettingReturnsTheDefaultWhenNoRowExistsYet(): void
    {
        self::assertNull($this->context->getSetting('p27-6-test-setting-does-not-exist'));
        self::assertSame('fallback', $this->context->getSetting('p27-6-test-setting-does-not-exist', 'fallback'));
    }

    public function testSetSettingThenGetSettingRoundTripsARealArbitraryKey(): void
    {
        $key = 'p27-6-test-setting';

        try {
            $this->context->setSetting($key, [
                'p_main_menu' => 'on',
                'p_pict_descr' => 'off',
            ]);

            self::assertSame([
                'p_main_menu' => 'on',
                'p_pict_descr' => 'off',
            ], $this->context->getSetting($key));
        } finally {
            $this->conn->executeStatement('DELETE FROM config WHERE param = ?', [$key]);
        }
    }

    /**
     * deleteSetting() (P27.12, grounded in `AdminTools`'s own real
     * `uninstall()` -- `conf_delete_param('AdminTools')` -- and
     * `LocalFilesEditor`'s/`modus`'s own raw-SQL reimplementations of the
     * same thing).
     */
    public function testDeleteSettingThenGetSettingReturnsTheGivenDefault(): void
    {
        $key = 'p27-12-test-setting';

        $this->context->setSetting($key, 'a-real-value');
        self::assertSame('a-real-value', $this->context->getSetting($key));

        $this->context->deleteSetting($key);

        self::assertSame('fallback', $this->context->getSetting($key, 'fallback'));
    }

    /**
     * mail() (P27.13) -- proves the passthrough to `MailService::mail()`
     * genuinely reaches the real send pipeline, not just that it doesn't
     * throw. Intercepts `BeforeSendMail` (`MailService::mail()`'s own
     * dispatch right before the real Transport call) and aborts the send
     * via `shouldSend: false`, the same lighter technique
     * `Integration\MailServiceTest::mailCaptureBeforeSend()` already
     * established -- no real network attempt, no
     * `MailServiceTestFakeSmtpServer` subprocess needed for a passthrough
     * check this narrow.
     */
    public function testMailReachesMailServiceWithTheGivenArguments(): void
    {
        $capturedEmail = null;
        $handler = function (BeforeSendMail $event) use (&$capturedEmail): BeforeSendMail {
            $capturedEmail = $event->email;

            return new BeforeSendMail(false, $event->to, $event->args, $event->email);
        };
        EventDispatcherTestFactory::get()->addTypedHandler(BeforeSendMail::class, $handler);

        try {
            $this->context->mail('someone@example.test', [
                'subject' => 'P27.13 test',
                'content' => 'hello from ExtensionContext::mail()',
            ]);
        } finally {
            EventDispatcherTestFactory::get()->removeEventHandler(BeforeSendMail::class, $handler);
        }

        if (! $capturedEmail instanceof Email) {
            throw new RuntimeException('expected the before_send_mail handler to have captured a real Email');
        }

        self::assertSame('P27.13 test', $capturedEmail->getSubject());
        self::assertStringContainsString('hello from ExtensionContext::mail()', (string) $capturedEmail->getTextBody());
    }

    /**
     * users()->listBasic() (P27.14) -- against the real fixture's 4
     * `users`/`user_infos` rows, proving id/username/status come back
     * correctly typed and ordered by username.
     */
    public function testUsersListBasicReturnsEveryFixtureUserOrderedByUsername(): void
    {
        $users = $this->context->users()
            ->listBasic();

        $summaries = array_map(
            static fn (BasicUserInfo $user): array => [$user->id->value, $user->username, $user->status],
            $users,
        );

        self::assertSame([
            [1, 'fixture_admin', UserStatus::Webmaster],
            [2, 'guest', UserStatus::Guest],
            [4, 'power_user', UserStatus::Normal],
            [3, 'regular_user', UserStatus::Normal],
        ], $summaries);
    }

    /**
     * themes()->listBasic() (P27.14) -- the fixture's `themes` table
     * starts empty, so this inserts one real row directly (cleaned up in
     * `finally`) rather than relying on ThemeRegistry's own heavier
     * install()/activate() flow, which ThemeRegistryTest.php already
     * covers end-to-end.
     */
    public function testThemesListBasicReturnsARealInstalledTheme(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO themes (id, version, name) VALUES ('p27-14-fixture-theme', '1.0.0', 'P27.14 Fixture Theme')",
        );

        try {
            $themes = $this->context->themes()
                ->listBasic();
        } finally {
            $this->conn->executeStatement("DELETE FROM themes WHERE id = 'p27-14-fixture-theme'");
        }

        $matching = array_values(array_filter($themes, static fn (BasicThemeInfo $theme): bool => $theme->id->value === 'p27-14-fixture-theme'));

        self::assertCount(1, $matching);
        self::assertSame('P27.14 Fixture Theme', $matching[0]->name);
    }

    /**
     * csrfToken()/checkCsrf() (P27.15) -- a real round trip through
     * `Csrf\CsrfService`'s own session-id + secret-key HMAC, proving
     * `ExtensionContext` reaches the real, container-shared collaborator
     * (not a throwaway/mocked one).
     */
    public function testCsrfTokenRoundTripsThroughCheckCsrf(): void
    {
        session_id('ext-context-csrf-test-' . uniqid('', true));

        $token = $this->context->csrfToken();
        self::assertNotSame('', $token);

        $_REQUEST['pwg_token'] = $token;
        try {
            self::assertTrue($this->context->checkCsrf());
        } finally {
            unset($_REQUEST['pwg_token']);
        }
    }

    public function testCheckCsrfReturnsNullWhenNoTokenSubmittedAndFalseForAWrongOne(): void
    {
        session_id('ext-context-csrf-test-' . uniqid('', true));

        self::assertNull($this->context->checkCsrf());

        $_REQUEST['pwg_token'] = 'clearly-wrong-token';
        try {
            self::assertFalse($this->context->checkCsrf());
        } finally {
            unset($_REQUEST['pwg_token']);
        }
    }

    public function testCheckCsrfOrFailThrowsResponseReadyExceptionForAWrongToken(): void
    {
        session_id('ext-context-csrf-test-' . uniqid('', true));
        $_REQUEST['pwg_token'] = 'clearly-wrong-token';

        try {
            $this->expectException(ResponseReadyException::class);
            $this->context->checkCsrfOrFail();
        } finally {
            unset($_REQUEST['pwg_token']);
        }
    }

    /**
     * isWebmaster() (P27.15) -- backed by the real `Auth\AccessControl`
     * service every other admin controller's own webmaster gate already
     * uses (e.g. `ConfigurationSubController`), not a bare
     * `currentUser()->status` equality this class could otherwise already
     * express directly.
     */
    public function testIsWebmasterReflectsTheCurrentUsersRealStatus(): void
    {
        CurrentUserTestFactory::get()->set(User::fromUserArray([
            'id' => 1,
            'status' => 'webmaster',
        ]));
        self::assertTrue($this->context->isWebmaster());

        CurrentUserTestFactory::get()->set(User::fromUserArray([
            'id' => 3,
            'status' => 'normal',
        ]));
        self::assertFalse($this->context->isWebmaster());
    }

    public function testImagesIsInCaddieReflectsARealInsertAndRemoval(): void
    {
        $caddieRepository = Kernel::container()->get(CaddieRepository::class);
        self::assertInstanceOf(CaddieRepository::class, $caddieRepository);
        // Cleans up first in case a prior, interrupted run of this same
        // test left a row behind -- the assertFalse() below only proves
        // the real behavior if the caddie genuinely starts empty.
        $caddieRepository->removeElementsForUser(1, [2]);

        self::assertFalse($this->context->images()->isInCaddie(1, 2));

        $caddieRepository->addElements(1, [2]);
        self::assertTrue($this->context->images()->isInCaddie(1, 2));

        $caddieRepository->removeElementsForUser(1, [2]);
        self::assertFalse($this->context->images()->isInCaddie(1, 2));
    }

    public function testImagesGetAddedByMatchesARawQuery(): void
    {
        $rawAddedBy = $this->conn->createQueryBuilder()
            ->select('added_by')
            ->from('images')
            ->where('id = :id')
            ->setParameter('id', 2)
            ->executeQuery()
            ->fetchOne();

        $expected = is_numeric($rawAddedBy) ? (int) $rawAddedBy : null;

        self::assertSame($expected, $this->context->images()->getAddedBy(2));
    }

    public function testImagesGetAddedByReturnsNullForANonexistentImage(): void
    {
        self::assertNull($this->context->images()->getAddedBy(999999));
    }

    public function testImagesGetRepresentativePictureIdMatchesARawQuery(): void
    {
        $categoryId = $this->conn->createQueryBuilder()
            ->select('id')
            ->from('categories')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        self::assertIsNumeric($categoryId, 'Fixture must contain at least one category');

        $rawRepresentative = $this->conn->createQueryBuilder()
            ->select('representative_picture_id')
            ->from('categories')
            ->where('id = :id')
            ->setParameter('id', (int) $categoryId)
            ->executeQuery()
            ->fetchOne();

        $expected = is_numeric($rawRepresentative) ? (int) $rawRepresentative : null;

        self::assertSame($expected, $this->context->images()->getRepresentativePictureId((int) $categoryId));
    }

    public function testImagesGetRepresentativePictureIdReturnsNullForANonexistentCategory(): void
    {
        self::assertNull($this->context->images()->getRepresentativePictureId(999999));
    }

    public function testTemplateThrowsBeforeFinalizeConstructsIt(): void
    {
        $currentTemplate = Kernel::container()->get(CurrentTemplate::class);
        self::assertInstanceOf(CurrentTemplate::class, $currentTemplate);
        $currentTemplate->reset();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('unavailable during boot()');

        $this->context->template();
    }

    public function testRedirectThrowsResponseReadyException(): void
    {
        $this->expectException(ResponseReadyException::class);

        $this->context->redirect('https://example.test/');
    }

    /**
     * config() returns the same shared CurrentConfig instance real core
     * code reads -- a theme's boot() mutating a public property must be
     * visible to core code reading the same property later in the same
     * request, proving this is genuinely the same instance, not a copy or
     * a read-only wrapper that makes the write a silent no-op.
     */
    public function testConfigReturnsTheSameSharedInstanceCoreCodeReads(): void
    {
        $this->context->config()
            ->galleryTitle = 'Set via ExtensionContext';

        self::assertSame('Set via ExtensionContext', $this->containerGet(CurrentConfig::class)->galleryTitle);
    }

    public function testCurrentUserReflectsWhateverCurrentUserHolds(): void
    {
        $this->impersonate(1);

        self::assertSame(1, $this->context->currentUser()->id->value);
    }

    public function testLangReturnsTheSharedLangInstance(): void
    {
        self::assertSame($this->containerGet(Lang::class), $this->context->lang());
    }

    public function testUrlReturnsTheSharedUrlServiceInstance(): void
    {
        self::assertSame($this->containerGet(UrlServiceInterface::class), $this->context->url());
    }

    /**
     * isAdminContext() is a real passthrough to Core\AdminContext::
     * isActive(), not a hardcoded value -- built from 2 separately
     * constructed ExtensionContext instances, one per AdminContext state,
     * since AdminContext's own value is fixed at container-build time
     * (immutable per request).
     */
    public function testIsAdminContextReflectsTheUnderlyingAdminContext(): void
    {
        $adminContext = $this->buildContext(PluginId::from('admin-plugin'), new AdminContext(true));
        $publicContext = $this->buildContext(PluginId::from('public-plugin'), new AdminContext(false));

        self::assertTrue($adminContext->isAdminContext());
        self::assertFalse($publicContext->isAdminContext());
    }

    /**
     * The exact scenario the P27 plan calls out by name: two active
     * plugins each writing session()->set('key', ...) with the same key
     * string must only ever read back their own value -- proving
     * ExtensionSession's namespacing is real, not just documented
     * convention.
     */
    public function testSessionIsNamespacedPerExtensionId(): void
    {
        $_SESSION = [];

        $pluginA = $this->buildContext(PluginId::from('plugin-a'));
        $pluginB = $this->buildContext(PluginId::from('plugin-b'));

        $pluginA->session()
            ->set('key', 'value-from-a');
        $pluginB->session()
            ->set('key', 'value-from-b');

        self::assertSame('value-from-a', $pluginA->session()->get('key'));
        self::assertSame('value-from-b', $pluginB->session()->get('key'));

        $pluginA->session()
            ->remove('key');
        self::assertNull($pluginA->session()->get('key'));
        self::assertSame('value-from-b', $pluginB->session()->get('key'), 'removing plugin-a\'s key must not affect plugin-b\'s');
    }

    public function testDispatchChangeReachesARegisteredHandler(): void
    {
        $eventDispatcher = $this->containerGet(EventDispatcher::class);
        $event = new ExtensionContextTestFakeEvent();
        $eventDispatcher->addTypedHandler(ExtensionContextTestFakeEvent::class, static function (ExtensionContextTestFakeEvent $e): ExtensionContextTestFakeEvent {
            $e->touched = true;

            return $e;
        });

        $result = $this->context->dispatchChange($event);

        self::assertInstanceOf(ExtensionContextTestFakeEvent::class, $result);
        self::assertTrue($result->touched);
    }

    public function testDispatchNotifyReachesARegisteredHandler(): void
    {
        $eventDispatcher = $this->containerGet(EventDispatcher::class);
        $touched = false;
        $eventDispatcher->addTypedHandler(ExtensionContextTestFakeEvent::class, static function (ExtensionContextTestFakeEvent $e) use (&$touched): void {
            $touched = true;
        });

        $this->context->dispatchNotify(new ExtensionContextTestFakeEvent());

        self::assertTrue($touched);
    }

    public function testExtensionInterfaceBootReceivesTheRealExtensionContext(): void
    {
        $plugin = new ExtensionContextTestFakePlugin();

        // Called through the interface type, not the concrete fake --
        // PluginRegistry/ThemeRegistry (P27.3) call boot() against a
        // manifest-resolved `ExtensionInterface $plugin` variable, never
        // a concrete class name, so this is the real call shape to
        // exercise. Piwigo\Tests\Unit\PluginConfig\ExtensionInterfaceTest
        // covers every other lifecycle hook + subscribedEvents() the same
        // way -- boot() needs a real ExtensionContext, so it's covered
        // here instead, where one already exists.
        $this->bootViaInterface($plugin, $this->context);

        self::assertSame($this->context, $plugin->receivedContext);

        // The other 5 lifecycle hooks are no-ops on this particular fake
        // (ExtensionInterfaceTestFakePlugin, Unit tier, already covers
        // their real behavior) -- called here only so this concrete
        // class's own overrides aren't flagged as dead code.
        $plugin->install();
        $plugin->activate();
        $plugin->deactivate();
        $plugin->uninstall();
        $plugin->update('1.0.0', '2.0.0');
        self::assertSame([], $plugin->subscribedEvents());
    }

    private function bootViaInterface(ExtensionInterface $plugin, ExtensionContext $context): void
    {
        $plugin->boot($context);
    }

    /**
     * Sets CurrentUser to fixture user_id 1 (the same admin row
     * AuthRepositoryTest.php's own tests target) -- same
     * `User::fromUserArray(['id' => $userId])` shape CaddieServiceTest.php
     * already uses for the identical "just need a real, resolvable id, not
     * a full authenticated-session flow" need.
     */
    private function impersonate(int $userId): void
    {
        CurrentUserTestFactory::get()->set(User::fromUserArray([
            'id' => $userId,
        ]));
    }
}
