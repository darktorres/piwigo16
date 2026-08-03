<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Admin\ThemesStandardPagesPageRenderer;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Lang;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\ScriptLoader;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;

/**
 * See ThemesStandardPagesPageRendererTest's own docblock below for why
 * this exists: a real chmod'd file can't isolate the render()'s
 * fopen()-only-fails "save_error" branch (finfo_file() needs the exact
 * same read access fopen() does, so an unreadable file fails both calls
 * identically and only ever reaches the earlier "Invalid image file."
 * branch -- confirmed live, `php -r` against a chmod 0000 file returns
 * false from both finfo_file() and fopen()). Every method here is called
 * reflectively by PHP's own stream-wrapper engine (never referenced
 * directly from PHP code), matching the streamWrapper class signatures
 * documented at php.net/manual/en/class.streamwrapper.php: stream_open()
 * serves real PNG bytes (so finfo_file() reports 'image/png', same as any
 * real upload) on the first open and returns false on every open after
 * that, so the *second* real open of the same path (the renderer's own
 * later fopen() call) genuinely fails.
 */
final class ThemesStandardPagesLogoStreamWrapper
{
    /**
     * Set by PHP's own streams engine on every registered wrapper instance
     * (php.net/manual/en/class.streamwrapper.php) -- must be explicitly
     * declared or PHP 8.2+ raises a dynamic-property-creation deprecation
     * the moment the engine assigns it.
     */
    public mixed $context = null;

    public static string $pngBytes = '';

    public static int $opens = 0;

    private string $buffer = '';

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        ++self::$opens;
        if (self::$opens > 1) {
            // 2nd+ open (the renderer's own later fopen() call) always
            // fails -- this is the entire point of this wrapper.
            return false;
        }

        $this->buffer = self::$pngBytes;
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->buffer, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->buffer);
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => strlen($this->buffer), 'mode' => 0100644];
    }

    /**
     * @return array<string, int>
     */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0100644, 'size' => strlen(self::$pngBytes)];
    }

    public function stream_cast(int $cast_as): bool
    {
        return false;
    }

    public function stream_close(): void {}
}

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
 *    "no write access" message. See ThemesStandardPagesLogoStreamWrapper
 *    above for how the 2nd branch is reached for real.
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
 * real Smarty template render (assign_var_from_handle('ADMIN_CONTENT',
 * 'themes'), which compiles and renders the real
 * themes_standard_pages.tpl) -- matching PageTailRendererTest/
 * MaintenanceActionDispatcherTest's own "construct the real renderer/
 * dispatcher directly, real Template, no fixture DB reset needed" shape.
 *
 * Writing this file's own fopen()-failure test surfaced a real,
 * pre-existing bug in the renderer, fixed alongside this test (see that
 * call site's own new comment): confUpdateParam('standard_pages_selected_
 * logo_path', ...) used to run unconditionally *before* fopen()/
 * writeStream() ever ran, so a failed write like this file's own 2nd test
 * still left config pointing at a logo file that was never actually
 * written on disk -- now persisted only once the write genuinely
 * succeeds, matching tests/Unit/Config/ConfigServiceTest.php's own stated
 * split ("DB-touching methods ... covered by
 * tests/Integration/ConfigServiceTest.php instead") for why this needs a
 * real ConfigService/DB connection rather than an unconnected one.
 *
 * CurrentPaths::siteLocal (not ::root) is the one property overridden for
 * the logo-upload tests, each to its own disposable sys_get_temp_dir()
 * root -- FilesystemHelper::mkgetdir()'s own $upload_dir is
 * `CurrentPaths::get()->siteLocal . 'logo'`, so this alone controls
 * exactly the directory these tests need to create/chmod, while ::root
 * stays the real repo root so Template can still find the real
 * themes/admin/default/template/themes_standard_pages.tpl. Never touches
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

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        // Both save_error strings this file asserts on live in admin.po --
        // loaded explicitly (not relying on some earlier Integration test
        // file in this shared process having already done it) so
        // Lang::t()'s real en_UK wording is deterministic regardless of run
        // order, same reasoning as MaintenanceActionDispatcherTest's own
        // setUp().
        Lang::load('admin.lang');

        $this->configService = new ConfigService($this->buildConfigRepository());
        CurrentConfigService::set($this->configService);

        // themes_standard_pages.tpl's own {combine_script}/{footer_script}
        // tags only *register* scripts (ScriptLoader::add()/add_inline(),
        // pure storage, confirmed by direct read) -- they never call
        // ScriptLoader's own static urlService() the way actually
        // *combining* them for real output would, but this is set anyway
        // for the same defensive reason PageTailRendererTest's own setUp()
        // sets it: real RequestBootstrap-only wiring this isolated test
        // never boots otherwise.
        ScriptLoader::setUrlService(new UrlService(new HtmlService()));
        CurrentTemplate::set(new Template(CurrentPaths::get()->root . 'themes/admin', 'default'));

        $this->renderer = $this->makeRenderer();

        $_POST = [];
        $_FILES = [];
    }

    #[\Override]
    protected function tearDown(): void
    {
        $_POST = [];
        $_FILES = [];
        CurrentTemplate::reset();
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
        parent::tearDown();
    }

    private function makeRenderer(): ThemesStandardPagesPageRenderer
    {
        // StorageRegistry is built fresh here (not container-resolved
        // once for the whole test) specifically so overrideSiteLocal()'s
        // own Kernel::boot() below is reflected in the 'local' disk
        // it builds -- config/storage.php's own 'local' factory reads
        // CurrentPaths::get() at fromConfig()-call time, same "must be
        // rebuilt after CurrentPaths changes" requirement a real request
        // never hits (CurrentPaths is fixed before the container ever
        // resolves anything, singleton/service-locator elimination
        // campaign, Phase 2).
        return new ThemesStandardPagesPageRenderer(
            new RedirectService(),
            new UrlService(new HtmlService()),
            $this->configService,
            StorageRegistry::fromConfig(dirname(__DIR__, 2) . '/config/storage.php'),
        );
    }

    private function overrideSiteLocal(string $siteLocal): void
    {
        $root = dirname(__DIR__, 2) . '/';
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
        $this->renderer = $this->makeRenderer();
    }

    private function realPngBytes(): string
    {
        $image = imagecreatetruecolor(4, 4);
        if ($image === false) {
            throw new \RuntimeException('imagecreatetruecolor failed');
        }
        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    public function test_mkgetdir_failure_assigns_the_add_write_access_sprintf_message(): void
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
            $_FILES['std_pgs_logo'] = ['tmp_name' => $realPng, 'name' => 'a-logo.png'];

            $this->renderer->render();

            $uploadDir = $fixtureRoot . 'logo';
            self::assertSame(
                sprintf(Lang::t('Add write access to the "%s" directory'), $uploadDir),
                CurrentTemplate::get()->get_template_vars('save_error')
            );
        } finally {
            @unlink($realPng);
        }
    }

    public function test_fopen_failure_on_the_source_tmp_file_assigns_the_plain_no_write_access_message(): void
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
            $_FILES['std_pgs_logo'] = ['tmp_name' => $scheme . '://fake-logo', 'name' => 'stdpageslogo.png'];

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
                "{$uploadDir}/stdpageslogo.png " . Lang::t('no write access'),
                CurrentTemplate::get()->get_template_vars('save_error')
            );

            // Regression guard for a real bug found and fixed alongside
            // this test: confUpdateParam('standard_pages_selected_logo_path',
            // ...) used to run unconditionally *before* fopen()/
            // writeStream() (see the renderer's own source comment at that
            // call site), so a failed write like this one still left config
            // pointing at a logo file that was never actually written. Now
            // that the persist only happens after a real successful write,
            // nothing should be written here at all.
            self::assertNull($this->rawConfigValue('standard_pages_selected_logo_path'));
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    private function rawConfigValue(string $param): ?string
    {
        $db = $this->newMysqli($this->dbName);
        $result = $db->query('SELECT value FROM ' . Tables::config() . " WHERE param = '{$param}'");
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_row();
        $db->close();
        if (! is_array($row)) {
            return null;
        }
        self::assertIsString($row[0]);

        return $row[0];
    }

    public function test_standard_pages_used_by_accumulates_only_the_real_theme_that_declares_the_flag(): void
    {
        $themesFixtureRoot = sys_get_temp_dir() . '/piwigo-std-pages-themes-' . bin2hex(random_bytes(6)) . '/';
        mkdir($themesFixtureRoot . 'themes', 0o777, true);
        $this->fixtureRootsToClean[] = $themesFixtureRoot;

        $this->writeFixtureTheme($themesFixtureRoot, 'std-pages-yes', 'Uses Standard Pages Theme', true);
        // No use_standard_pages key at all -- same shape as the real
        // themes/default on disk (see this file's own docblock), proving
        // this isn't simply "every scanned theme gets pushed".
        $this->writeFixtureTheme($themesFixtureRoot, 'std-pages-no', 'Plain Theme', null);

        CurrentConfig::setThemesDir(rtrim($themesFixtureRoot, '/') . '/themes');

        $this->renderer->render();

        $template = CurrentTemplate::get();
        self::assertTrue($template->get_template_vars('is_standard_pages_used'));
        self::assertSame(['Uses Standard Pages Theme'], $template->get_template_vars('standard_pages_used_by'));
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
