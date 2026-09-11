<?php

declare(strict_types=1);

namespace Piwigo\Tests\PortedExtensions\Modus;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Override;
use Piwigo\Asset\Event\GetPageAssets;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\CookieService;
use Piwigo\Caddie\CaddieRepository;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigRepository;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AdminContext;
use Piwigo\Core\ApiContext;
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
use Piwigo\Db\TypedRepository;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Mail\MailService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\ExtensionContextFactory;
use Piwigo\PluginConfig\Facade\CategoryWriteFacade;
use Piwigo\PluginConfig\Facade\ImageReadFacade;
use Piwigo\PluginConfig\Facade\ImageWriteFacade;
use Piwigo\PluginConfig\Facade\ThemeReadFacade;
use Piwigo\PluginConfig\Facade\UserReadFacade;
use Piwigo\PluginConfig\ThemeRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Event\GetColorscheme;
use Piwigo\Template\Renderer;
use Piwigo\Tests\Integration\IntegrationTestCase;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Theme\Modus\SkinCatalog;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use ZipArchive;

/**
 * Covers the real `modus` port (`../piwigo16-themes/modus_17.0.0`,
 * piwigo17-rewrite's P29.6) end-to-end against a real DB + the real
 * sibling-repo filesystem (not a fixture copy) -- `install()`/
 * `activate()`/`uninstall()` via a real `ThemeRegistry` pointed at that
 * repo, and the real `GetColorscheme` override once `bootCurrent()` has
 * registered `Theme::onGetColorscheme()` against the live event
 * dispatcher.
 *
 * `../piwigo16-themes/modus_17.0.0.zip`'s own real internal folder is
 * bare `modus/` (the hard `id` === extracted-directory-basename
 * requirement `ThemeRegistry::loadManifest()` enforces) -- the
 * *versioned* `modus_17.0.0/` sibling directory this catalog repo keeps
 * it in is that catalog's own naming convention, not what a real
 * install's own `themes/` ever looks like. So this test extracts the
 * real zip into a throwaway temp `themes/`-shaped directory first,
 * exactly like a real PEM install's own fetch-extract step (minus the
 * HTTP fetch, using the local zip file directly) -- pointing
 * `ThemeRegistry` at the versioned source directory directly would
 * fail that basename check outright.
 *
 * Skipped entirely when `../piwigo16-themes/modus_17.0.0.zip` doesn't
 * exist on disk (a fresh checkout of this repo alone, without its
 * sibling porting-catalog repos checked out) -- this suite's whole
 * reason to exist is exercising that sibling content for real, not a
 * fixture substitute.
 */
final class ModusThemeIntegrationTest extends IntegrationTestCase
{
    private const string MODUS_PORT_ZIP = '../piwigo16-themes/modus_17.0.0.zip';

    private static bool $fixtureReady = false;

    private Connection $conn;

    private ThemeRepository $repository;

    private EventDispatcher $eventDispatcher;

    private ExtensionContextFactory $contextFactory;

    private CurrentConfig $currentConfig;

    private string $extractedThemesDir;

    #[Override]
    protected function setUp(): void
    {
        if (! is_file(self::MODUS_PORT_ZIP)) {
            self::markTestSkipped(self::MODUS_PORT_ZIP . ' not found -- sibling porting-catalog repo not checked out.');
        }

        $this->extractedThemesDir = sys_get_temp_dir() . '/modus_theme_integration_test_' . uniqid('', true);
        mkdir($this->extractedThemesDir, 0o777, true);
        $zip = new ZipArchive();
        if ($zip->open(self::MODUS_PORT_ZIP) !== true) {
            self::fail('Could not open ' . self::MODUS_PORT_ZIP);
        }
        $zip->extractTo($this->extractedThemesDir);
        $zip->close();

        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 3) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        DbTransactionTestOverride::begin();

        $this->conn = DbConnection::build();

        // Template's own constructor needs a populated CurrentConfigService
        // (testGetPageAssetsContributesMenuhScriptWithoutThrowing() builds a
        // real one) -- wired the same way MailGoldenHtmlSnapshotTest.php's
        // own setUp() does; IntegrationTestCase::tearDown() resets it after
        // every test.
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        $configRepo = TypedRepository::narrow(EntityManagerFactory::build($this->conn)->getRepository(ConfigEntry::class), ConfigRepository::class);
        $configService = new ConfigService($configRepo, CurrentConfigTestFactory::get());
        CurrentConfigServiceTestFactory::get()
            ->set($configService);
        $configService->loadConfFromDb();

        $currentUser = Kernel::container()->get(CurrentUser::class);
        if (! $currentUser instanceof CurrentUser) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentUser::class);
        }
        $currentUser->attachGlobals();

        $this->repository = $this->containerGet(ThemeRepository::class);
        $this->eventDispatcher = $this->containerGet(EventDispatcher::class);
        $this->currentConfig = $this->containerGet(CurrentConfig::class);

        $imageReadFacade = new ImageReadFacade(
            $this->containerGet(CaddieRepository::class),
            $this->containerGet(ImageRepository::class),
            $this->containerGet(CategoryRepository::class),
        );
        $imageWriteFacade = new ImageWriteFacade(
            $this->containerGet(ImageService::class),
            $this->containerGet(TagService::class),
            $this->containerGet(UrlServiceInterface::class),
        );
        $categoryWriteFacade = new CategoryWriteFacade($this->containerGet(CategoryService::class));
        $this->contextFactory = new ExtensionContextFactory(
            $this->containerGet(CurrentTemplate::class),
            $this->currentConfig,
            $currentUser,
            $this->containerGet(UserService::class),
            $this->containerGet(Lang::class),
            $this->containerGet(UrlServiceInterface::class),
            $this->containerGet(RedirectServiceInterface::class),
            $this->containerGet(AdminContext::class),
            $this->containerGet(ApiContext::class),
            $this->eventDispatcher,
            $this->containerGet(SessionService::class),
            $imageReadFacade,
            $this->containerGet(Paths::class),
            $this->containerGet(ConfigService::class),
            $this->containerGet(EntityManagerInterface::class),
            $this->containerGet(MailService::class),
            new UserReadFacade($this->containerGet(UserRepository::class)),
            new ThemeReadFacade($this->repository),
            $this->containerGet(CsrfService::class),
            $this->containerGet(HtmlRenderingInterface::class),
            $this->containerGet(AccessControl::class),
            $imageWriteFacade,
            $categoryWriteFacade,
            new Renderer($this->containerGet(CurrentTemplate::class)),
            $this->containerGet(CookieService::class),
            $this->containerGet(ImageStdParams::class),
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        if (! is_file(self::MODUS_PORT_ZIP)) {
            return;
        }

        $this->conn->executeStatement("DELETE FROM themes WHERE id = 'modus'");
        DbTransactionTestOverride::rollback();
        parent::tearDown();
        self::removeDirRecursive($this->extractedThemesDir);
    }

    private static function removeDirRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? self::removeDirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testActivateSeedsDefaultSettingsAndUninstallDeletesThem(): void
    {
        $registry = $this->realRegistry();

        $registry->install('modus');
        $registry->activate('modus');

        $context = $this->contextFactory->build(ThemeId::from('modus'));
        self::assertSame(
            [
                'skin' => SkinCatalog::DEFAULT_SKIN_ID,
                'album_thumb_size' => 250,
                'index_photo_deriv' => ImageStdParams::XXSMALL,
                'index_photo_deriv_hdpi' => ImageStdParams::XSMALL,
                'display_page_banner' => false,
            ],
            $context->getSetting('modus_theme'),
        );

        $registry->uninstall('modus');
        self::assertNull($this->contextFactory->build(ThemeId::from('modus'))->getSetting('modus_theme'));
    }

    public function testActivateNeverResetsAnExistingSavedSettingToDefaults(): void
    {
        $registry = $this->realRegistry();

        $registry->install('modus');
        $registry->activate('modus');

        $context = $this->contextFactory->build(ThemeId::from('modus'));
        $context->setSetting('modus_theme', [
            'skin' => 'dark_sky',
            'album_thumb_size' => 300,
            'index_photo_deriv' => 'small',
            'index_photo_deriv_hdpi' => 'medium',
            'display_page_banner' => true,
        ]);

        // A real re-activation (deactivate() then activate() again) must
        // not reset a webmaster's own saved choice back to defaults.
        $registry->activate('modus');

        $saved = $context->getSetting('modus_theme');
        self::assertSame('dark_sky', is_array($saved) ? ($saved['skin'] ?? null) : null);
    }

    public function testGetColorschemeReflectsTheActiveSkinOnceBootCurrentRegistersTheHandler(): void
    {
        $registry = $this->realRegistry();
        $registry->install('modus');
        $registry->activate('modus');

        $context = $this->contextFactory->build(ThemeId::from('modus'));
        $context->setSetting('modus_theme', [
            'skin' => 'newspaper',
        ]);

        $registry->bootCurrent(ThemeId::from('modus'));

        $event = $this->eventDispatcher->dispatch(new GetColorscheme(ThemeId::from('modus'), 'this-should-be-overridden'));
        self::assertSame('light', $event->colorscheme, 'newspaper is a real light skin (SkinCatalog)');

        $context->setSetting('modus_theme', [
            'skin' => 'dark_sky',
        ]);
        $registry->bootCurrent(ThemeId::from('modus'));
        $event = $this->eventDispatcher->dispatch(new GetColorscheme(ThemeId::from('modus'), 'this-should-be-overridden'));
        self::assertSame('dark', $event->colorscheme, 'dark_sky is a real dark skin (SkinCatalog)');
    }

    /**
     * Real bug this test locks in: `GetColorscheme` fires from inside
     * `Template`'s own constructor, *before* `CurrentTemplate::set()`
     * runs (`RequestBootstrap::finalize()`'s real call order) --
     * `ExtensionContext::template()` throws if called from that
     * handler, in the real pipeline, not only when a test dispatches
     * the event directly. `GetPageAssets` (dispatched from
     * `Renderer::render()`, well after `CurrentTemplate` is set) is the
     * one this port's own `Theme::onGetPageAssets()` uses instead --
     * this test builds a real `Template`, registers it as *the* current
     * one (matching what `RequestBootstrap::finalize()` does for a real
     * request), and confirms dispatching doesn't throw.
     */
    public function testGetPageAssetsContributesMenuhScriptWithoutThrowing(): void
    {
        $registry = $this->realRegistry();
        $registry->install('modus');
        $registry->activate('modus');

        $template = TemplateTestFactory::build(
            root: rtrim($this->extractedThemesDir, '/'),
            theme: 'modus',
        );
        CurrentTemplateTestFactory::get()
            ->set($template);

        $registry->bootCurrent(ThemeId::from('modus'));

        $event = $this->eventDispatcher->dispatch(new GetPageAssets());

        $byId = [];
        foreach ($event->assets as $asset) {
            $byId[$asset->id] = $asset;
        }
        self::assertArrayHasKey('modus-menuh', $byId, 'Theme::onGetPageAssets() must contribute the modus-menuh script');
        self::assertSame('themes/modus/dist/menuh.js', $byId['modus-menuh']->path);
        self::assertArrayHasKey('modus-async', $byId, 'Theme::onGetPageAssets() must contribute the modus-async script');
        self::assertSame('themes/modus/dist/modus-async.js', $byId['modus-async']->path);
    }

    private function realRegistry(): ThemeRegistry
    {
        $this->currentConfig->themesDir = rtrim($this->extractedThemesDir, '/');

        return new ThemeRegistry(
            $this->repository,
            $this->eventDispatcher,
            $this->contextFactory,
            $this->currentConfig,
            Paths::fromRoot(dirname(__DIR__, 3)),
            $this->containerGet(Lang::class),
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
}
