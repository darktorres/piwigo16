<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Override;
use Piwigo\Admin\ThemesStandardPagesPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Users\UserService;
use RuntimeException;

/**
 * Piwigo\Admin\ThemesStandardPagesPageRenderer::render() -- see
 * tests/Browser/ThemesStandardPagesPageRendererTest.php for the
 * HTTP-reachable coverage (webmaster warning, save submission, the real
 * "invalid mime"/"success" upload outcomes). This file closes the 2
 * branches that Browser test's own docblock explicitly calls out as
 * unreachable there:
 *
 * 1) The 2 filesystem "save_error" variants inside the logo-upload block
 *    (~lines 155-176 of the renderer). Direct read of the source shows
 *    these are NOT "missing directory" vs. "existing but unwritable
 *    directory" (both of those collapse to the *same* sprintf message
 *    here, since FilesystemHelper::mkgetdir() only returns a bool, not a
 *    reason) -- the real differentiator is which of 2 separate I/O calls
 *    failed: FilesystemHelper::mkgetdir($upload_dir, ...) failing (the
 *    *destination* directory can't be created/isn't writable) produces
 *    the sprintf 'Add write access to the "%s" directory' message, vs.
 *    fopen($std_pgs_logo_tmp_name, 'rb') failing (rereading the already
 *    validated *source* upload tmp file, right after finfo_file() already
 *    opened and read it once to sniff its MIME type) produces the plain
 *    "no write access" message. See
 *    tests/Integration/ThemesStandardPagesLogoStreamWrapper.php for how
 *    the 2nd branch is reached for real.
 *
 * 2) The standard_pages_used_by accumulation (~lines 186-191): needs a
 *    real theme on disk whose themeconf.inc.php declares
 *    'use_standard_pages' => true, which tests/Browser's own docblock
 *    explains is out of scope there (the live themes/ tree only has
 *    themes/default, which declares no such key, and
 *    ExtensionType::Theme->scanDirectory() hardcodes CurrentConfig::
 *    themesPath() with no per-request injection point reachable over
 *    HTTP). tests/Unit/Admin/ThemesInstalledPageRendererTest.php's own
 *    docblock hits the identical constraint for a sibling class and
 *    solves it the same way: CurrentConfig::setThemesDir() pointed at a
 *    disposable fixture root instead of the live, git-tracked themes/
 *    tree, each fixture theme carrying its own screenshot.png so
 *    ExtensionScanner::scanTheme()'s hidden PreferencesService DB
 *    fallback branch is never reached.
 *
 * Integration (not Unit) tier because render()'s own tail always calls a
 * real Latte template render (assignVarFromTemplate('ADMIN_CONTENT',
 * 'themes_standard_pages.latte'), which compiles and renders the real
 * themes_standard_pages.latte) -- matching PageTailRendererTest/
 * MaintenanceActionDispatcherTest's own "construct the real renderer/
 * dispatcher directly, real Template, no fixture DB reset needed" shape.
 *
 * confUpdateParam('standard_pages_selected_logo_path', ...) (see that
 * call site's own comment) only runs once fopen()/writeStream() has
 * genuinely succeeded -- a failed write like this file's own 2nd test
 * leaves config pointing at no logo file at all, matching
 * tests/Unit/Config/ConfigServiceTest.php's own stated split ("DB-touching
 * methods ... covered by tests/Integration/ConfigServiceTest.php instead")
 * for why this needs a real ConfigService/DB connection rather than an
 * unconnected one.
 *
 * CurrentPaths::siteLocal (not ::root) is the one property overridden for
 * the logo-upload tests, each to its own disposable sys_get_temp_dir()
 * root -- FilesystemHelper::mkgetdir()'s own $upload_dir is
 * `CurrentPathsTestFactory::get()->siteLocal . 'logo'`, so this alone controls
 * exactly the directory these tests need to create/chmod, while ::root
 * stays the real repo root so Template can still find the real
 * themes/admin/default/template/themes_standard_pages.latte. Never touches
 * the real, shared local/logo/ directory tests/Browser's own logo-upload
 * test already writes into and cleans up.
 */
final class ThemesStandardPagesPageRendererTest extends IntegrationTestCase
{
    private ConfigService $configService;

    private ThemesStandardPagesPageRenderer $renderer;

    /**
     * @var list<string>
     */
    private array $fixtureRootsToClean = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        // Both save_error strings this file asserts on live in admin.po --
        // loaded explicitly (not relying on some earlier Integration test
        // file in this shared process having already done it) so
        // Lang::t()'s real en_UK wording is deterministic regardless of run
        // order, same reasoning as MaintenanceActionDispatcherTest's own
        // setUp().
        LangTestFactory::get()->load('admin.lang');

        $this->configService = new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfigTestFactory::get());
        CurrentConfigServiceTestFactory::get()->set($this->configService);

        // themes_standard_pages.latte's own {combine_script}/{footer_script}
        // tags only *register* scripts (ScriptLoader::add()/add_inline(),
        // pure storage, confirmed by direct read) -- they never call
        // ScriptLoader's own static urlService() the way actually
        // *combining* them for real output would, but this is set anyway
        // for the same defensive reason PageTailRendererTest's own setUp()
        // sets it: real RequestBootstrap-only wiring this isolated test
        // never boots otherwise.
        CurrentTemplateTestFactory::get()->set(TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes/admin', 'default'));

        $this->renderer = $this->makeRenderer();

        $_POST = [];
        $_FILES = [];
    }

    #[Override]
    protected function tearDown(): void
    {
        $_POST = [];
        $_FILES = [];
        CurrentTemplateTestFactory::get()->reset();
        foreach ($this->fixtureRootsToClean as $root) {
            if (is_dir($root)) {
                // Fixture roots below are deliberately chmod'd unwritable by
                // some tests -- restore write access first or deltree()'s
                // own recursive unlink()/rmdir() calls would fail partway
                // through.
                chmod($root, 0o777);
                FilesystemHelper::deltree($root);
            }
        }
        $this->fixtureRootsToClean = [];
        // overrideSiteLocal() (called by 2 of this file's tests) leaves
        // Kernel booted with a throwaway fixture Paths -- reset so the next
        // test's parent::setUp() gets a fresh default (real repo root) boot
        // instead of silently reusing this test's fixture root.
        Kernel::reset();
        parent::tearDown();
    }

    private function userService(): UserService
    {
        // Kernel is already booted by this point (either parent::setUp()'s
        // own default boot, or overrideSiteLocal()'s throwaway-fixture
        // reboot) -- resolve the same container-shared instance a real
        // request would get.
        $userService = Kernel::container()->get(UserService::class);
        if (! $userService instanceof UserService) {
            throw new LogicException('Container returned an unexpected type for ' . UserService::class);
        }

        return $userService;
    }

    private function accessControl(): AccessControl
    {
        $accessControl = Kernel::container()->get(AccessControl::class);
        if (! $accessControl instanceof AccessControl) {
            throw new LogicException('Container returned an unexpected type for ' . AccessControl::class);
        }

        return $accessControl;
    }

    private function makeRenderer(): ThemesStandardPagesPageRenderer
    {
        // StorageRegistry is built fresh here (not container-resolved
        // once for the whole test) so that overrideSiteLocal()'s own
        // Kernel::boot() below is reflected in the 'local' disk it builds
        // -- config/storage.php's own 'local' factory closure captures
        // whichever CurrentPathsTestFactory::get()/CurrentConfigTestFactory::get()
        // instance is passed in explicitly at fromConfig()-call time, so it
        // must be rebuilt after CurrentPaths changes. A real request never
        // hits this because CurrentPaths is fixed before the container ever
        // resolves anything.
        return new ThemesStandardPagesPageRenderer(
            LangTestFactory::get(),
            $this->accessControl(),
            new RedirectService(LangTestFactory::get(), $this->userService(), EventDispatcherTestFactory::get(), PageStateTestFactory::get()),
            UrlServiceTestFactory::build(),
            $this->configService,
            StorageRegistry::fromConfig(dirname(__DIR__, 2) . '/config/storage.php', CurrentPathsTestFactory::get(), CurrentConfigTestFactory::get()),
            PageStateTestFactory::get(),
            CurrentTemplateTestFactory::get(),
            HtmlServiceTestFactory::build(),
            CurrentConfigTestFactory::get(),
            CurrentPathsTestFactory::get(),
            CurrentUserTestFactory::get(),
            EventDispatcherTestFactory::get(),
        );
    }

    private function overrideSiteLocal(string $siteLocal): void
    {
        $root = dirname(__DIR__, 2) . '/';
        // parent::setUp()'s own conditional default boot (real repo root)
        // already booted Kernel by this point -- without this reset,
        // Kernel::boot()'s own idempotency guard makes the call below a
        // silent no-op, leaving CurrentPaths pointing at the real repo
        // instead of this test's own throwaway fixture root (same fix as
        // InstallBootstrapTest/InstallWizardTest).
        Kernel::reset();
        Kernel::boot(new Paths(
            root: $root,
            plugins: $root . 'plugins/',
            themes: $root . 'themes/',
            local: $root . 'local/',
            siteLocal: $siteLocal,
            data: $root . '_data/',
            derivatives: $root . '_data/i/',
            logs: $root . '_data/logs/',
            upload: $root . 'upload/',
            config: $root . 'config/',
            vendor: $root . 'vendor/',
        ));
        // Kernel::reset() above also discards the container-shared
        // CurrentUser instance parent::setUp()'s own attachGlobals() seed
        // populated -- without reseeding here, AccessControl::isWebmaster()
        // (read by this renderer) throws "not initialised" against this
        // fresh, unseeded container.
        CurrentUserTestFactory::get()->attachGlobals();
        // Same reasoning again -- Kernel::reset() also discards the
        // container-shared CurrentConfigService instance setUp()'s own
        // set() call populated; without reseeding here, Template's own
        // constructor (its Tier-2 CurrentConfigServiceTestFactory::get()->get()
        // read, see that class's own docblock) throws "not initialised"
        // against this fresh, unseeded container the moment the new
        // Template below is constructed.
        CurrentConfigServiceTestFactory::get()->set($this->configService);
        // Same reasoning as the CurrentUser reseed above -- Kernel::reset()
        // also discards the container-shared CurrentTemplate instance
        // setUp()'s own set() call populated; without reseeding here,
        // this renderer's own $this->currentTemplate->get() throws "not
        // initialised" against this fresh, unseeded container.
        CurrentTemplateTestFactory::get()->set(TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes/admin', 'default'));
        $this->renderer = $this->makeRenderer();
    }

    private function realPngBytes(): string
    {
        $image = imagecreatetruecolor(4, 4);
        if ($image === false) {
            throw new RuntimeException('imagecreatetruecolor failed');
        }
        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    public function testMkgetdirFailureAssignsTheAddWriteAccessSprintfMessage(): void
    {
        $fixtureRoot = sys_get_temp_dir() . '/piwigo-std-pages-mkgetdir-' . bin2hex(random_bytes(6)) . '/';
        mkdir($fixtureRoot, 0o777, true);
        $this->fixtureRootsToClean[] = $fixtureRoot;
        $this->overrideSiteLocal($fixtureRoot);

        // $fixtureRoot . 'logo' (the real $upload_dir) doesn't exist yet, so
        // mkgetdir() tries to create it -- FilesystemHelper::
        // nearestExistingAncestor() walks up to $fixtureRoot itself (the
        // nearest dir that *does* exist), and this chmod makes that
        // ancestor unwritable, exactly the "missing directory, unwritable
        // parent" case UploadServiceTest's own readyForUploadMessage test
        // exercises for a sibling class -- mkdir() itself is never even
        // reached (mkgetdir()'s own is_writable() short-circuit).
        chmod($fixtureRoot, 0o555);

        $realPng = tempnam(sys_get_temp_dir(), 'pwg_std_pages_mkgetdir_') . '.png';
        file_put_contents($realPng, $this->realPngBytes());

        try {
            $_FILES['std_pgs_logo'] = [
                'tmp_name' => $realPng,
                'name' => 'a-logo.png',
            ];

            $this->renderer->render();

            $uploadDir = $fixtureRoot . 'logo';
            self::assertSame(
                sprintf(LangTestFactory::get()->t('Add write access to the "%s" directory'), $uploadDir),
                CurrentTemplateTestFactory::get()->get()->getTemplateVars('save_error')
            );
        } finally {
            @unlink($realPng);
        }
    }

    public function testFopenFailureOnTheSourceTmpFileAssignsThePlainNoWriteAccessMessage(): void
    {
        $fixtureRoot = sys_get_temp_dir() . '/piwigo-std-pages-fopen-' . bin2hex(random_bytes(6)) . '/';
        // logo/ already exists and is writable -- mkgetdir() succeeds
        // without ever calling mkdir(), isolating this test to the *other*
        // failure this renderer's logo-upload block can produce.
        mkdir($fixtureRoot . 'logo', 0o777, true);
        $this->fixtureRootsToClean[] = $fixtureRoot;
        $this->overrideSiteLocal($fixtureRoot);

        $scheme = 'pwgteststdpageslogostream';
        ThemesStandardPagesLogoStreamWrapper::$pngBytes = $this->realPngBytes();
        ThemesStandardPagesLogoStreamWrapper::$opens = 0;
        self::assertTrue(stream_wrapper_register($scheme, ThemesStandardPagesLogoStreamWrapper::class));

        try {
            $_FILES['std_pgs_logo'] = [
                'tmp_name' => $scheme . '://fake-logo',
                'name' => 'stdpageslogo.png',
            ];

            // The renderer's own fopen() call (2nd open) genuinely fails
            // and raises a real E_WARNING -- this project's phpunit.xml.dist
            // (failOnWarning) would otherwise convert that into a test
            // failure. Suppressed here deliberately, same technique as
            // BackupServiceTest.php's own set_error_handler() use, so the
            // renderer reaches its own "no write access" handling instead
            // of a PHPUnit\Framework\Error\Warning.
            set_error_handler(static fn (): bool => true, E_WARNING);
            try {
                $this->renderer->render();
            } finally {
                restore_error_handler();
            }

            // Confirms finfo_file() (1st open, succeeds) really did see a
            // recognized 'image/png' mime and reach the mkgetdir()-TRUE
            // branch, and that fopen() (2nd open) is the call that failed --
            // not a fluke of the wrapper never being read at all.
            self::assertSame(2, ThemesStandardPagesLogoStreamWrapper::$opens);

            $uploadDir = $fixtureRoot . 'logo';
            self::assertSame(
                "{$uploadDir}/stdpageslogo.png " . LangTestFactory::get()->t('no write access'),
                CurrentTemplateTestFactory::get()->get()->getTemplateVars('save_error')
            );

            // confUpdateParam('standard_pages_selected_logo_path', ...)
            // (see the renderer's own source comment at that call site)
            // only persists after a real successful write -- a failed
            // write like this one must leave nothing recorded.
            self::assertNull($this->rawConfigValue('standard_pages_selected_logo_path'));
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    private function rawConfigValue(string $param): ?string
    {
        $value = DbConnection::build()->fetchOne(
            "SELECT value FROM config WHERE param = '{$param}'"
        );
        if ($value === false) {
            return null;
        }
        self::assertIsString($value);

        return $value;
    }

    public function testStandardPagesUsedByAccumulatesOnlyTheRealThemeThatDeclaresTheFlag(): void
    {
        $themesFixtureRoot = sys_get_temp_dir() . '/piwigo-std-pages-themes-' . bin2hex(random_bytes(6)) . '/';
        mkdir($themesFixtureRoot . 'themes', 0o777, true);
        $this->fixtureRootsToClean[] = $themesFixtureRoot;

        $this->writeFixtureTheme($themesFixtureRoot, 'std-pages-yes', 'Uses Standard Pages Theme', true);
        // No use_standard_pages key at all -- same shape as the real
        // themes/default on disk (see this file's own docblock), proving
        // this isn't simply "every scanned theme gets pushed".
        $this->writeFixtureTheme($themesFixtureRoot, 'std-pages-no', 'Plain Theme', null);

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->themesDir = rtrim($themesFixtureRoot, '/') . '/themes';

        $this->renderer->render();

        $template = CurrentTemplateTestFactory::get()->get();
        self::assertTrue($template->getTemplateVars('is_standard_pages_used'));
        self::assertSame(['Uses Standard Pages Theme'], $template->getTemplateVars('standard_pages_used_by'));
    }

    private function writeFixtureTheme(string $fixtureRoot, string $id, string $name, ?bool $useStandardPages): void
    {
        $dir = $fixtureRoot . 'themes/' . $id;
        mkdir($dir, 0o777, true);
        $lines = "<?php\n/*\nTheme Name: {$name}\nVersion: 1.0\n*/\n";
        if ($useStandardPages !== null) {
            $flag = $useStandardPages ? 'true' : 'false';
            $lines .= "\$theme_conf['use_standard_pages'] = {$flag};\n";
        }
        file_put_contents($dir . '/themeconf.inc.php', $lines);
        // Avoids ExtensionScanner::scanTheme()'s hidden PreferencesService DB
        // fallback branch for a missing screenshot.png -- same reasoning as
        // tests/Unit/Admin/ThemesInstalledPageRendererTest.php's own
        // writeThemesInstalledFixtureTheme().
        file_put_contents($dir . '/screenshot.png', 'x');
    }
}
