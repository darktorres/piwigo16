<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use Override;
use Piwigo\Admin\LoadedPlugins;
use Piwigo\Auth\AccessControl;
use Piwigo\Caddie\CaddieRepository;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigRepository;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\PluginSubController;
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
use Piwigo\Http\ResponseReadyException;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Mail\MailService;
use Piwigo\PluginConfig\CurrentPluginRegistry;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\ExtensionContextFactory;
use Piwigo\PluginConfig\Facade\CategoryWriteFacade;
use Piwigo\PluginConfig\Facade\ImageReadFacade;
use Piwigo\PluginConfig\Facade\ImageWriteFacade;
use Piwigo\PluginConfig\Facade\ThemeReadFacade;
use Piwigo\PluginConfig\Facade\UserReadFacade;
use Piwigo\PluginConfig\PluginMigrationRepository;
use Piwigo\PluginConfig\PluginRegistry;
use Piwigo\PluginConfig\PluginRepository;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;

/**
 * End-to-end coverage for the settings-page mechanism, at the
 * `Controller\Admin\PluginSubController` layer this fork's own
 * `PluginRegistryTest.php`/`ThemeRegistryTest.php` don't reach (those
 * stop at `PluginRegistry`/`ThemeRegistry` themselves) -- a real fixture
 * plugin, installed and activated against a real DB, dispatched through
 * a real `PluginSubController::handle()` call with a real PSR-7 request,
 * proving the whole chain (`CurrentPluginRegistry` ->
 * `PluginRegistry::getBootedInstance()` -> `SettingsPageInterface::
 * handleSettingsRequest()` -> `ExtensionContext::checkCsrfOrFail()`/
 * `setSetting()`, its returned `View` -> `PluginSubController`'s own
 * `Renderer::render()` + `AdminContentPageContext` assignment)
 * actually reaches real, working collaborators, not just that each
 * piece compiles in isolation.
 */
final class PluginSettingsPageDispatchTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private PluginRepository $repository;

    private PluginMigrationRepository $migrationRepository;

    private EventDispatcher $eventDispatcher;

    private ExtensionContextFactory $contextFactory;

    /**
     * @var list<string>
     */
    private array $tempDirs = [];

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

        $this->conn = DbConnection::build();

        // TemplateTestFactory::build() below constructs a real Template,
        // whose constructor needs a populated CurrentConfigService --
        // IntegrationTestCase::tearDown() resets it after every test.
        // Wired here the same way ExtensionContextTest.php's own setUp()
        // does (see that file for the fuller explanation).
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        $configRepo = TypedRepository::narrow(EntityManagerFactory::build($this->conn)->getRepository(ConfigEntry::class), ConfigRepository::class);
        $configService = new ConfigService($configRepo, CurrentConfigTestFactory::get());
        CurrentConfigServiceTestFactory::get()->set($configService);
        $configService->loadConfFromDb();

        $currentUser = Kernel::container()->get(CurrentUser::class);
        if (! $currentUser instanceof CurrentUser) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentUser::class);
        }
        $currentUser->attachGlobals();

        $this->repository = $this->containerGet(PluginRepository::class);
        $this->migrationRepository = $this->containerGet(PluginMigrationRepository::class);
        $this->eventDispatcher = $this->containerGet(EventDispatcher::class);

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
            $this->containerGet(CurrentConfig::class),
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
            new ThemeReadFacade($this->containerGet(ThemeRepository::class)),
            $this->containerGet(CsrfService::class),
            $this->containerGet(HtmlRenderingInterface::class),
            $this->containerGet(AccessControl::class),
            $imageWriteFacade,
            $categoryWriteFacade,
            new Renderer($this->containerGet(CurrentTemplate::class)),
        );

        $this->containerGet(CurrentTemplate::class)->set(TemplateTestFactory::build());
    }

    #[Override]
    protected function tearDown(): void
    {
        // Ledger before plugins: fk_plugin_migrations_plugin_id is ON DELETE
        // RESTRICT, so the plugin row cannot go first.
        $this->conn->executeStatement("DELETE FROM plugin_migrations WHERE plugin_id LIKE 'zz-%'");
        $this->conn->executeStatement("DELETE FROM plugins WHERE id LIKE 'zz-%'");
        $this->conn->executeStatement("DELETE FROM config WHERE param LIKE 'zz-%'");
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        unset($_GET['section'], $_REQUEST['pwg_token']);
        DbTransactionTestOverride::rollback();
        parent::tearDown();
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

    private function buildRegistry(string $pluginsDir): PluginRegistry
    {
        $base = Paths::fromRoot(dirname(__DIR__, 2));
        $paths = new Paths(
            root: $base->root,
            plugins: $pluginsDir,
            themes: $base->themes,
            local: $base->local,
            siteLocal: $base->siteLocal,
            data: $base->data,
            derivatives: $base->derivatives,
            logs: $base->logs,
            upload: $base->upload,
            config: $base->config,
            vendor: $base->vendor,
        );

        return new PluginRegistry(
            $this->repository,
            $this->migrationRepository,
            $this->eventDispatcher,
            $this->contextFactory,
            $this->containerGet(CurrentConfig::class),
            $paths,
            $this->conn,
        );
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/piwigo_settings_page_dispatch_test_' . uniqid('', true);
        mkdir($dir, 0o777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Writes a fixture plugin implementing both `ExtensionInterface` and
     * `SettingsPageInterface` -- `handleSettingsRequest()` returns a
     * typed `View` (P43-D, docs/PLAN.md), whose `#[Template(...)]`
     * attribute names this fixture's own real `.latte` file by its real,
     * already-known absolute path -- a dynamically-generated fixture can
     * freely embed that as a literal (unlike a real, hand-authored
     * plugin, whose source is fixed long before any specific install
     * path is known; that's what the `myplugin:` prefix `TemplateLocator`
     * loader detail P43-D's own design note defers is actually for). On
     * POST additionally calls `checkCsrfOrFail()` then persists
     * `$settingKey` via `setSetting()` -- the real `LocalFilesEditor`-
     * shaped "fail-fast CSRF, then persist" pattern this phase's own
     * design is grounded in.
     */
    private function writeFixtureSettingsPlugin(string $pluginsDir, string $id, string $namespaceSuffix, string $settingKey): string
    {
        $dir = $pluginsDir . '/' . $id;
        mkdir($dir . '/src', 0o777, true);
        mkdir($dir . '/template', 0o777, true);

        $namespace = 'PiwigoTest\\SettingsFixture' . $namespaceSuffix;
        $className = 'Plugin' . $namespaceSuffix;
        $viewClassName = 'PluginView' . $namespaceSuffix;
        $templatePath = $dir . '/template/admin.latte';
        file_put_contents($templatePath, 'Fixture settings page rendered.');

        file_put_contents($dir . '/plugin.json', json_encode([
            'id' => $id,
            'name' => $id,
            'version' => '1.0.0',
            'description' => 'Fixture settings-page plugin',
            'license' => 'MIT',
            'minPiwigo' => '16.3.0',
            'main' => $namespace . '\\' . $className,
            'hasSettings' => true,
            'autoload' => [
                'psr-4' => [
                    $namespace . '\\' => 'src/',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        file_put_contents($dir . '/src/' . $viewClassName . '.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Piwigo\\Core\\View;
            use Piwigo\\Template\\Latte\\Attribute\\Template;

            #[Template('{$templatePath}')]
            final readonly class {$viewClassName} implements View
            {
            }
            PHP);

        file_put_contents($dir . '/src/' . $className . '.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Piwigo\\Core\\View;
            use Piwigo\\PluginConfig\\ExtensionContext;
            use Piwigo\\PluginConfig\\ExtensionInterface;
            use Piwigo\\PluginConfig\\SettingsPageInterface;
            use Psr\\Http\\Message\\ServerRequestInterface;

            final class {$className} implements ExtensionInterface, SettingsPageInterface
            {
                private ?ExtensionContext \$context = null;

                public function boot(ExtensionContext \$context): void
                {
                    \$this->context = \$context;
                }

                public function install(): void {}
                public function activate(): void {}
                public function deactivate(): void {}
                public function uninstall(): void {}
                public function update(string \$oldVersion, string \$newVersion): void {}
                public function subscribedEvents(): array { return []; }

                public function handleSettingsRequest(ServerRequestInterface \$request): View
                {
                    if (\$this->context === null) {
                        throw new \\LogicException('boot() was never called');
                    }

                    if (\$request->getMethod() === 'POST') {
                        \$this->context->checkCsrfOrFail();
                        \$body = \$request->getParsedBody();
                        \$value = is_array(\$body) ? (\$body['{$settingKey}'] ?? null) : null;
                        \$this->context->setSetting('{$settingKey}', is_string(\$value) ? \$value : '');
                    }

                    return new {$viewClassName}();
                }
            }
            PHP);

        return $namespace . '\\' . $className;
    }

    private function buildController(PluginRegistry $registry, string $id): PluginSubController
    {
        $loadedPlugins = new LoadedPlugins();
        $loadedPlugins->set([
            $id => [
                'id' => $id,
                'state' => 'active',
                'version' => '1.0.0',
            ],
        ]);

        $currentPluginRegistry = new CurrentPluginRegistry();
        $currentPluginRegistry->set($registry);

        return new PluginSubController(
            $loadedPlugins,
            $this->containerGet(HtmlRenderingInterface::class),
            new InputValidator(),
            $currentPluginRegistry,
            new Renderer($this->containerGet(CurrentTemplate::class)),
        );
    }

    public function testGetRequestReachesHandleSettingsRequestAndRendersRealContent(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $id = 'zz-settings-dispatch-' . $suffix;
        $settingKey = 'zz-setting-' . $suffix;
        $this->writeFixtureSettingsPlugin($dir, $id, 'Get' . $suffix, $settingKey);

        $registry = $this->buildRegistry($dir);
        $registry->install($id);
        $registry->activate($id);
        $registry->bootActive();

        $controller = $this->buildController($registry, $id);
        $_GET['section'] = $id . '/admin.php';

        $rendered = $controller->handle(new ServerRequest('GET', '/admin.php'))
            ->content;

        self::assertStringContainsString('Fixture settings page rendered.', (string) $rendered);
    }

    public function testPostRequestWithValidCsrfTokenPersistsTheSubmittedSetting(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $id = 'zz-settings-dispatch-' . $suffix;
        $settingKey = 'zz-setting-' . $suffix;
        $this->writeFixtureSettingsPlugin($dir, $id, 'Post' . $suffix, $settingKey);

        $registry = $this->buildRegistry($dir);
        $registry->install($id);
        $registry->activate($id);
        $registry->bootActive();

        $controller = $this->buildController($registry, $id);
        $_GET['section'] = $id . '/admin.php';

        session_id('settings-dispatch-csrf-' . $suffix);
        $_REQUEST['pwg_token'] = $this->containerGet(CsrfService::class)->getToken();

        $request = new ServerRequest('POST', '/admin.php', [], null, '1.1', [])
            ->withParsedBody([
                $settingKey => 'a real submitted value',
            ]);
        $controller->handle($request);

        self::assertSame(
            'a real submitted value',
            $this->containerGet(ConfigService::class)->confGetParam($settingKey, 'DEFAULT'),
        );
    }

    public function testPostRequestWithAWrongCsrfTokenThrowsAndDoesNotPersist(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $id = 'zz-settings-dispatch-' . $suffix;
        $settingKey = 'zz-setting-' . $suffix;
        $this->writeFixtureSettingsPlugin($dir, $id, 'Bad' . $suffix, $settingKey);

        $registry = $this->buildRegistry($dir);
        $registry->install($id);
        $registry->activate($id);
        $registry->bootActive();

        $controller = $this->buildController($registry, $id);
        $_GET['section'] = $id . '/admin.php';

        session_id('settings-dispatch-csrf-bad-' . $suffix);
        $_REQUEST['pwg_token'] = 'clearly-wrong-token';

        $request = new ServerRequest('POST', '/admin.php', [], null, '1.1', [])
            ->withParsedBody([
                $settingKey => 'must-not-be-persisted',
            ]);

        $this->expectException(ResponseReadyException::class);

        try {
            $controller->handle($request);
        } finally {
            self::assertSame(
                'DEFAULT',
                $this->containerGet(ConfigService::class)->confGetParam($settingKey, 'DEFAULT'),
            );
        }
    }
}
