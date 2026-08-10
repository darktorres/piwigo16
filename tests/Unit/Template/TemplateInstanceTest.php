<?php

declare(strict_types=1);

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Url\RootPathOverride;
use Smarty\Smarty;
use Piwigo\Template\CssLoader;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\KernelContainerOverride;
use Piwigo\Core\AdminContext;
use Piwigo\Tests\Unit\Template\TemplateInstanceTestFakeStatStreamWrapper;
use Smarty\Extension\BCPluginsAdapter;
use Piwigo\Template\Css;
use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Core\AppInfo;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Kernel;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Template\Event\CombinedCss;
use Piwigo\Template\Event\CombinedScript;
use Piwigo\Template\PwgTemplateAdapter;
use Piwigo\Template\Template;
use Piwigo\Tests\Support\CurrentUserTestFactory;

/**
 * Template::urlService() resolves the container-shared UrlServiceInterface
 * (and, via its own constructor, RootPathOverride) live on every call --
 * there is no bare RootPathOverride::push()/reset() static call.
 */
function template_instance_test_root_path_override(): RootPathOverride
{
    $rootPathOverride = Kernel::container()->get(RootPathOverride::class);
    if (! $rootPathOverride instanceof RootPathOverride) {
        throw new LogicException('Container returned an unexpected type for ' . RootPathOverride::class);
    }

    return $rootPathOverride;
}

/**
 * @return array<int|string, object>
 */
function template_instance_test_smarty_pre_filters(Smarty $smarty): array
{
    $property = new ReflectionProperty($smarty, 'BCPluginsAdapter');
    /** @var BCPluginsAdapter $adapter */
    $adapter = $property->getValue($smarty);

    return $adapter->getPreFilters();
}

function template_instance_test_uppercase_prefilter(string $source, \Smarty\Template $template): string
{
    return strtoupper($source);
}

function template_instance_test_lowercase_prefilter(string $source, \Smarty\Template $template): string
{
    return strtolower($source);
}

/**
 * @return array<int|string, object>
 */
function template_instance_test_smarty_post_filters(Smarty $smarty): array
{
    $property = new ReflectionProperty($smarty, 'BCPluginsAdapter');
    /** @var BCPluginsAdapter $adapter */
    $adapter = $property->getValue($smarty);

    return $adapter->getPostFilters();
}

/**
 * @return array<string, Css>
 */
function template_instance_test_cssloader_registered(CssLoader $loader): array
{
    $property = new ReflectionProperty($loader, 'registered_css');

    /** @var array<string, Css> */
    return $property->getValue($loader);
}

/**
 * Piwigo\Template\Template -- instance-level methods needing a real,
 * constructible Template (Smarty engine booted, real filesystem template
 * dir) but no DB: TemplateTest.php's own docblock deliberately keeps to
 * static, instance-free logic, so every instance method below (the bulk
 * of this class's own gap) had zero coverage. Same "point CurrentPaths at
 * a fresh temp root" construction setup as PictureRateRendererTest.php's
 * own docblock. func_define_derivative() is the one instance method that
 * genuinely needs a real DB (ImageStdParams::get_custom()'s own save()
 * call) -- see tests/Integration/TemplateDefineDerivativeTest.php instead.
 */
function template_instance_test_rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        is_dir($path) ? template_instance_test_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

/**
 * Writes a minimal, real themeconf.inc.php fixture -- exercised through a
 * genuine `include` (load_themeconf()'s own contract), matching the bare
 * `$themeconf = [...]` assignment shape every real themes/*\/themeconf.inc.php
 * in this repo uses. Always carries a 'local_head' key (defaulting to '')
 * unless the caller overrides it -- set_theme()'s own local_head branch
 * reads $themeconf['local_head'] directly (not via `??`) in its
 * is_string() clause, so an actually-missing key would trigger a real
 * "Undefined array key" warning (failOnWarning=true) for every set_theme()
 * test that isn't specifically exercising that gap.
 *
 * @param array<string, mixed> $vars
 */
function template_instance_test_write_themeconf(string $dir, array $vars): void
{
    if (! is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }
    $vars += ['local_head' => ''];
    file_put_contents(
        $dir . '/themeconf.inc.php',
        "<?php\n\$themeconf = " . var_export($vars, true) . ";\n",
    );
}

/**
 * Narrows Template::get_template_vars()'s mixed return (it delegates
 * straight to Smarty\Smarty::getTemplateVars()) down to the "list of
 * per-theme arrays" shape set_theme() itself always appends.
 *
 * @return list<array<string, mixed>>
 */
function template_instance_test_themes(Template $t): array
{
    $themes = $t->get_template_vars('themes');
    if (! is_array($themes) || ! array_is_list($themes)) {
        throw new RuntimeException('Expected themes to be a list, got ' . get_debug_type($themes));
    }

    $narrowed = [];
    foreach ($themes as $theme) {
        $narrowed[] = template_instance_test_assoc($theme);
    }

    return $narrowed;
}

/**
 * @return array<string, mixed>
 */
function template_instance_test_assoc(mixed $value): array
{
    if (! is_array($value)) {
        throw new RuntimeException('Expected an array, got ' . get_debug_type($value));
    }

    $narrowed = [];
    foreach ($value as $key => $v) {
        if (! is_string($key)) {
            throw new RuntimeException('Expected a string-keyed array, found key ' . get_debug_type($key));
        }

        $narrowed[$key] = $v;
    }

    return $narrowed;
}

/**
 * @return array<string, string|null>
 */
function template_instance_test_save_server_keys(): array
{
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? null;
    $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
    $phpSelf = $_SERVER['PHP_SELF'] ?? null;

    return [
        'SCRIPT_NAME' => is_string($scriptName) ? $scriptName : null,
        'SCRIPT_FILENAME' => is_string($scriptFilename) ? $scriptFilename : null,
        'PHP_SELF' => is_string($phpSelf) ? $phpSelf : null,
    ];
}

/**
 * @param array<string, string|null> $saved
 */
function template_instance_test_restore_server_keys(array $saved): void
{
    foreach ($saved as $key => $value) {
        if ($value === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $value;
        }
    }
}

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-template-instance-test-' . bin2hex(random_bytes(8));
    // Captured on $this, not re-read via CurrentPathsTestFactory::get() in afterEach()
    // below -- a test using KernelContainerOverride::with() (e.g. the
    // admin-context one further down) leaves Kernel reset by the time
    // afterEach() runs, so CurrentPathsTestFactory::get() would throw there.
    $this->root = $root;
    mkdir($root, 0o777, true);
    // A prior test file left Kernel booted without resetting first would
    // otherwise make the boot() call below silently no-op, leaving
    // CurrentPathsTestFactory (and every CurrentPathsTestFactory::get()->root
    // -based mutation throughout this file) pointed at whatever root that
    // earlier boot bound instead of this fixture root.
    Kernel::reset();
    // Template's own ProcessCache usage goes through a static shim
    // (Template isn't converted to constructor injection, see that
    // shim's own docblock), which needs a real container. CurrentPaths
    // is itself a pure shim reading Paths::class straight out of
    // that same container, so this one Kernel::boot() call establishes both.
    Kernel::boot(Paths::fromRoot($root));
    // Booted first (above) -- CurrentConfigTestFactory::get()/CurrentUserTestFactory::get()
    // must resolve the container-shared instance, not the memoized pre-boot
    // fallback, or these seeds are invisible to every later current()->get()
    // call (same pitfall Translator/EventDispatcher hit too).
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentUserTestFactory::get()->attachGlobals();
});

afterEach(function (): void {
    template_instance_test_rrmdir($this->root);
    CurrentUserTestFactory::get()->reset();
    CurrentConfigTestFactory::get()->reset();
    EventDispatcherTestFactory::get()->reset();
    Kernel::reset();
});

// --- constructor: Smarty engine base config -----------------------------

test('constructor disables Smarty html escaping', function (): void {
    $t = TemplateTestFactory::build();

    expect($t->smarty->escape_html)->toBeFalse();
});

test('constructor lowers error_reporting to exclude E_NOTICE when template debugging is off', function (): void {
    CurrentConfigTestFactory::get()->debugTemplate = false;

    $t = TemplateTestFactory::build();

    expect($t->smarty->error_reporting)->toBe(error_reporting() & ~E_NOTICE);
});

test('constructor leaves error_reporting untouched when template debugging is on', function (): void {
    CurrentConfigTestFactory::get()->debugTemplate = true;

    $t = TemplateTestFactory::build();

    expect($t->smarty->error_reporting)->toBeNull();
});

test('constructor casts compile_check to an int', function (): void {
    CurrentConfigTestFactory::get()->templateCompileCheck = true;

    $t = TemplateTestFactory::build();

    expect($t->smarty->compile_check)->toBe(1);
});

// --- constructor: data dir not writable -------------------------------

test('constructor fatal-errors when the data directory cannot be made writable', function (): void {
    $this->expectErrorLog();
    chmod(CurrentPathsTestFactory::get()->root, 0o555);
    CurrentConfigTestFactory::get()->reset();
    CurrentConfigTestFactory::get()->dataLocation = 'data/';

    set_error_handler(static fn (): bool => true);
    try {
        TemplateTestFactory::build();
    } finally {
        restore_error_handler();
        chmod(CurrentPathsTestFactory::get()->root, 0o755);
    }
})->throws(ResponseReadyException::class);

test('constructor requests no backtrace when reporting the data-dir-not-writable error', function (): void {
    $this->expectErrorLog();
    chmod(CurrentPathsTestFactory::get()->root, 0o555);
    CurrentConfigTestFactory::get()->reset();
    CurrentConfigTestFactory::get()->dataLocation = 'data/';

    $body = null;
    set_error_handler(static fn (): bool => true);
    try {
        TemplateTestFactory::build();
    } catch (ResponseReadyException $e) {
        $body = (string) $e->response()->getBody();
    } finally {
        restore_error_handler();
        chmod(CurrentPathsTestFactory::get()->root, 0o755);
    }

    expect($body)->not->toBeNull()
        ->and($body)->not->toContain("#1\t");
});

test('constructor loads admin.lang before rendering the data-dir-not-writable error, so its own real translation is used', function (): void {
    // Real gettext fixture (same PoLoader/Translator stack LangTest.php's
    // own langTestWritePo() uses) placed under the *test* root's own
    // language/ dir -- Lang::load('admin.lang') resolves dirname from
    // CurrentPathsTestFactory::get()->root, not the repo's real top-level language/.
    // Without the real load('admin.lang') call, Lang::t('an error
    // happened') falls back to returning the raw key untranslated
    // (Translator::translate()'s own documented fallback) instead of this
    // fixture's translation, so this only passes when the load() call
    // genuinely ran first.
    // Lang::load()'s own po-sibling resolution strips a literal ".lang.php"
    // suffix down to ".po" -- for filename "admin.lang", the appended-.php
    // form is "admin.lang.php", whose po sibling is "admin.po" (matching
    // this repo's own real ./language/*/admin.po naming), not
    // "admin.lang.po".
    $this->expectErrorLog();
    mkdir(CurrentPathsTestFactory::get()->root . 'language/en_UK', 0o777, true);
    file_put_contents(
        CurrentPathsTestFactory::get()->root . 'language/en_UK/admin.po',
        "msgid \"\"\nmsgstr \"\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Language: en_UK\\n\"\n\nmsgid \"an error happened\"\nmsgstr \"CUSTOM-ADMIN-LANG-TITLE\"\n",
    );
    chmod(CurrentPathsTestFactory::get()->root, 0o555);
    CurrentConfigTestFactory::get()->reset();
    CurrentConfigTestFactory::get()->dataLocation = 'data/';

    $body = null;
    set_error_handler(static fn (): bool => true);
    try {
        TemplateTestFactory::build();
    } catch (ResponseReadyException $e) {
        $body = (string) $e->response()->getBody();
    } finally {
        restore_error_handler();
        chmod(CurrentPathsTestFactory::get()->root, 0o755);
        TranslatorTestFactory::get()->reset();
        LangTestFactory::get()->reset();
    }

    expect($body)->not->toBeNull()
        ->and($body)->toContain('<h1>CUSTOM-ADMIN-LANG-TITLE</h1>');
});

test('constructor creates the configured data-location directory when data_dir_checked is unset', function (): void {
    CurrentConfigTestFactory::get()->dataLocation = 'mydata/';
    CurrentConfigTestFactory::get()->dataDirChecked = null;

    try {
        TemplateTestFactory::build();
    } catch (LogicException) {
        // CurrentConfigService isn't initialised in this Unit test -- by
        // the time confUpdateParam() reaches it and throws, the
        // mkgetdir()/is_writable() check above (what this test verifies)
        // has already run.
    }

    expect(is_dir(CurrentPathsTestFactory::get()->root . 'mydata'))->toBeTrue();
});

test('constructor actually reaches CurrentConfigService::confUpdateParam() when data_dir_checked is unset, not just the local isset() check', function (): void {
    // The try/catch around this call only catches Doctrine\DBAL\Exception
    // -- CurrentConfigServiceTestFactory::get()->get() itself throws a plain \LogicException
    // when unset (never initialised in this Unit test), which propagates
    // straight out of the constructor. That only happens if the
    // confUpdateParam() call site is genuinely still reached; removing it
    // entirely would let construction finish without throwing anything.
    CurrentConfigServiceTestFactory::get()->reset();
    CurrentConfigTestFactory::get()->dataLocation = 'mydata3/';
    CurrentConfigTestFactory::get()->dataDirChecked = null;

    expect(static fn (): Template => TemplateTestFactory::build())
        ->toThrow(LogicException::class, 'CurrentConfigService not initialised');
});

// --- constructor: compile dir / pwg assign / plugin registration -------

test('constructor creates and sets the templates_c compile dir', function (): void {
    $t = TemplateTestFactory::build();

    $expected = CurrentPathsTestFactory::get()->root . 'data/templates_c';

    expect(is_dir($expected))->toBeTrue()
        ->and($t->smarty->getCompileDir())->toBe($expected . '/');
});

test('constructor assigns the pwg template adapter', function (): void {
    $t = TemplateTestFactory::build();

    expect($t->get_template_vars('pwg'))->toBeInstanceOf(PwgTemplateAdapter::class);
});

test('constructor registers exactly one Smarty pre-filter (prefilter_white_space)', function (): void {
    $t = TemplateTestFactory::build();

    expect(template_instance_test_smarty_pre_filters($t->smarty))->toHaveCount(1);
});

test('constructor registers every expected Smarty plugin', function (): void {
    $t = TemplateTestFactory::build();

    /** @var array<string, array<string, mixed>> $registered */
    $registered = $t->smarty->registered_plugins;

    expect(array_keys($registered['modifiercompiler']))->toBe(['translate', 'translate_dec']);
    expect(array_keys($registered['block']))->toBe(['html_head', 'html_style', 'footer_script']);
    expect(array_keys($registered['function']))->toBe(['combine_script', 'get_combined_scripts', 'combine_css', 'define_derivative']);
    expect(array_keys($registered['compiler']))->toBe(['get_combined_css']);
    expect(array_keys($registered['modifier']))->toBe([
        'sprintf', 'urlencode', 'intval', 'file_exists', 'constant', 'json_encode',
        'json_decode', 'htmlspecialchars', 'implode', 'stripslashes', 'in_array',
        'ucfirst', 'strstr', 'stristr', 'trim', 'md5', 'strtolower', 'str_ireplace',
        'explode', 'ternary', 'get_extent', 'url_is_remote', 'is_null', 'l10n',
        'str_replace', 'is_admin', 'is_classic_user', 'get_device', 'is_file',
        'strpos', 'preg_match', 'get_gallery_home_url', 'sizeOf', 'array_key_exists',
    ]);
});

test('constructor registers the language postfilter only when cache-by-language is on', function (): void {
    CurrentConfigTestFactory::get()->compiledTemplateCacheLanguage = true;

    $t = TemplateTestFactory::build();

    expect(template_instance_test_smarty_post_filters($t->smarty))->toHaveCount(1);
});

test('constructor does not register the language postfilter when cache-by-language is off', function (): void {
    CurrentConfigTestFactory::get()->compiledTemplateCacheLanguage = false;

    $t = TemplateTestFactory::build();

    expect(template_instance_test_smarty_post_filters($t->smarty))->toHaveCount(0);
});

test('constructor resets Smarty template dir to empty before adding its own (root=".")', function (): void {
    // Smarty's own default template_dir is ['./templates/'] (resolved
    // absolute internally) -- without the reset, addTemplateDir() below
    // would append onto that default instead of replacing it, and
    // get_template_dir() (index 0) would still read back the untouched
    // Smarty default (cwd + '/templates/') instead of just cwd + '/'.
    $t = TemplateTestFactory::build();

    expect($t->get_template_dir())->toBe(getcwd() . '/');
});

test('constructor derives jquery_code and plupload_code from the lang code when not already set', function (): void {
    LangTestFactory::get()->setLangInfo(['code' => 'en-UK']);

    $t = TemplateTestFactory::build();

    $expected = ['code' => 'en-UK', 'jquery_code' => 'en-UK', 'plupload_code' => 'en_UK'];
    expect(LangTestFactory::get()->langInfo())->toBe($expected)
        ->and($t->get_template_vars('lang_info'))->toBe($expected);
});

test('constructor registers template-extension extents when not in admin context, later duplicates winning', function (): void {
    mkdir(CurrentPathsTestFactory::get()->root . '/template-extension/', 0o777, true);
    file_put_contents(CurrentPathsTestFactory::get()->root . '/template-extension/first.tpl', 'a');
    file_put_contents(CurrentPathsTestFactory::get()->root . '/template-extension/second.tpl', 'b');
    CurrentConfigTestFactory::get()->extentsForTemplates = [
        'first.tpl' => ['dup-handle', 'N/A', 'N/A'],
        'second.tpl' => ['dup-handle', 'N/A', 'N/A'],
    ];

    $t = TemplateTestFactory::build();

    expect($t->get_extent('orig.tpl', 'dup-handle'))->toBe(realpath(CurrentPathsTestFactory::get()->root . '/template-extension/second.tpl'));
});

// --- constructor: local-css header prefilter (themed, non-admin) -------

test('constructor registers the local-css header prefilter for a themed template when not in admin context', function (): void {
    // AdminContext defaults to inactive -- beforeEach()'s own Kernel::boot()
    // already bound the default (false), no explicit setup needed.
    $t = TemplateTestFactory::build('.', 'template-instance-test-theme-a');

    expect($t->external_filters)->toHaveKey('header');
});

test('constructor does not register the local-css header prefilter for a themed template while in admin context', function (): void {
    // KernelContainerOverride::with() rebuilds the container from scratch,
    // so Paths::class needs re-supplying alongside the deliberate
    // AdminContext override -- captured from the live container
    // beforeEach() already booted, before with()'s own Kernel::reset()
    // discards it. CurrentConfig::class needs the same treatment: a fresh
    // container builds its own fresh CurrentConfig instance, at its own
    // class defaults, discarding beforeEach()'s own setDataLocation()/
    // setDataDirChecked() writes -- re-supplying the SAME already-configured
    // instance keeps Template's constructor from re-reaching the (in this
    // fresh container, uninitialised) CurrentConfigService.
    $paths = CurrentPathsTestFactory::get();
    $currentConfig = CurrentConfigTestFactory::get();
    KernelContainerOverride::with(
        [
            AdminContext::class => new AdminContext(true),
            Paths::class => $paths,
            CurrentConfig::class => $currentConfig,
        ],
        function (): void {
            $t = TemplateTestFactory::build('.', 'template-instance-test-theme-b');

            expect($t->external_filters)->not->toHaveKey('header');
        }
    );
});

// --- set_theme -----------------------------------------------------------

test('set_theme loads themeconf from exactly root/theme, joined with a literal slash', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/concat-theme', ['marker' => 'root-slash-theme']);
    $t = TemplateTestFactory::build();

    $t->set_theme($root, 'concat-theme', 'template');

    expect($t->get_themeconf('marker'))->toBe('root-slash-theme');
});

test('set_theme recognizes every whitelisted auth-page basename for the standard-pages swap', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/wl-theme', ['marker' => 'not-swapped']);
    template_instance_test_write_themeconf($root . '/standard_pages', ['marker' => 'swapped']);
    CurrentConfigTestFactory::get()->useStandardPages = true;
    $saved = template_instance_test_save_server_keys();

    try {
        foreach (['identification', 'register', 'password', 'profile'] as $basename) {
            $_SERVER['SCRIPT_NAME'] = '/' . $basename . '.php';
            unset($_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);

            $t = TemplateTestFactory::build();
            $t->set_theme($root, 'wl-theme', 'template');

            expect($t->get_themeconf('marker'))->toBe('swapped');
        }
    } finally {
        template_instance_test_restore_server_keys($saved);
    }
});

test('set_theme does not swap themes when the current page is not a whitelisted auth page', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/not-auth-theme', ['marker' => 'not-swapped']);
    template_instance_test_write_themeconf($root . '/standard_pages', ['marker' => 'swapped']);
    CurrentConfigTestFactory::get()->useStandardPages = true;
    $saved = template_instance_test_save_server_keys();
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    unset($_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);
    $t = TemplateTestFactory::build();

    try {
        $t->set_theme($root, 'not-auth-theme', 'template');
    } finally {
        template_instance_test_restore_server_keys($saved);
    }

    expect($t->get_themeconf('marker'))->toBe('not-swapped');
});

test('set_theme never swaps away from the "default" theme itself even on a whitelisted auth page', function (): void {
    // Also proves the first `and` genuinely short-circuits the whole
    // condition (not an `or`): with theme==='default', an `or`-mutated
    // first join would let the (matching) auth-page clause alone drag the
    // whole condition true, still swapping away from 'default'.
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/default', ['marker' => 'default-marker']);
    template_instance_test_write_themeconf($root . '/standard_pages', ['marker' => 'swapped']);
    CurrentConfigTestFactory::get()->useStandardPages = true;
    $saved = template_instance_test_save_server_keys();
    $_SERVER['SCRIPT_NAME'] = '/identification.php';
    unset($_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);
    $t = TemplateTestFactory::build();

    try {
        $t->set_theme($root, 'default', 'template');
    } finally {
        template_instance_test_restore_server_keys($saved);
    }

    expect($t->get_themeconf('marker'))->toBe('default-marker');
});

test('set_theme swaps themes when the theme itself opts into standard pages, even if the global config does not', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/opt-in-theme', ['marker' => 'not-swapped', 'use_standard_pages' => true]);
    template_instance_test_write_themeconf($root . '/standard_pages', ['marker' => 'swapped']);
    CurrentConfigTestFactory::get()->useStandardPages = false;
    $saved = template_instance_test_save_server_keys();
    $_SERVER['SCRIPT_NAME'] = '/identification.php';
    unset($_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);
    $t = TemplateTestFactory::build();

    try {
        $t->set_theme($root, 'opt-in-theme', 'template');
    } finally {
        template_instance_test_restore_server_keys($saved);
    }

    expect($t->get_themeconf('marker'))->toBe('swapped');
});

test('set_theme does not swap themes when neither the theme nor the global config opts into standard pages', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/opt-out-theme', ['marker' => 'not-swapped']);
    template_instance_test_write_themeconf($root . '/standard_pages', ['marker' => 'swapped']);
    CurrentConfigTestFactory::get()->useStandardPages = false;
    $saved = template_instance_test_save_server_keys();
    $_SERVER['SCRIPT_NAME'] = '/identification.php';
    unset($_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);
    $t = TemplateTestFactory::build();

    try {
        $t->set_theme($root, 'opt-out-theme', 'template');
    } finally {
        template_instance_test_restore_server_keys($saved);
    }

    expect($t->get_themeconf('marker'))->toBe('not-swapped');
});

test('set_theme recurses into a distinct parent theme', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/child-theme', ['marker' => 'child', 'parent' => 'parent-theme']);
    template_instance_test_write_themeconf($root . '/parent-theme', ['marker' => 'parent']);
    $t = TemplateTestFactory::build();

    $t->set_theme($root, 'child-theme', 'template');

    $themes = template_instance_test_themes($t);
    expect($themes)->toHaveCount(2)
        // The parent's own recursive set_theme() call appends its themes
        // entry before this (outer, child) call resumes and appends its
        // own -- so the parent lands at index 0, the child at index 1.
        ->and($themes[0]['id'])->toBe('parent-theme')
        ->and($themes[1]['id'])->toBe('child-theme');
});

test('set_theme does not recurse when a theme names itself as its own parent', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/self-parent-theme', ['marker' => 'self', 'parent' => 'self-parent-theme']);
    $t = TemplateTestFactory::build();

    $t->set_theme($root, 'self-parent-theme', 'template');

    expect($t->get_template_vars('themes'))->toHaveCount(1);
});

test('set_theme records both the theme id and the load_css flag on the appended themes entry', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/plain-theme', ['marker' => 'x']);
    $t = TemplateTestFactory::build();

    $t->set_theme($root, 'plain-theme', 'template', false);

    $themes = template_instance_test_themes($t);
    expect($themes[0]['id'])->toBe('plain-theme')
        ->and($themes[0]['load_css'])->toBeFalse();
});

test('set_theme resolves local_head to a real file path when present and load_local_head is true', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    mkdir($root . '/lh-theme', 0o777, true);
    file_put_contents($root . '/lh-theme/local_head.tpl', 'x');
    template_instance_test_write_themeconf($root . '/lh-theme', ['marker' => 'x', 'local_head' => 'local_head.tpl']);
    $t = TemplateTestFactory::build();

    $t->set_theme($root, 'lh-theme', 'template', true, true);

    $themes = template_instance_test_themes($t);
    expect($themes[0]['local_head'])->toBe(realpath($root . '/lh-theme/local_head.tpl'));
});

test('set_theme treats a local_head value of "0" as absent, same as every other in_array sentinel', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/lh-zero-theme', ['marker' => 'x', 'local_head' => '0']);
    $t = TemplateTestFactory::build();

    $t->set_theme($root, 'lh-zero-theme', 'template', true, true);

    expect(template_instance_test_themes($t)[0])->not->toHaveKey('local_head');
});

test('set_theme treats an empty-string local_head as absent', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/lh-empty-theme', ['marker' => 'x', 'local_head' => '']);
    $t = TemplateTestFactory::build();

    $t->set_theme($root, 'lh-empty-theme', 'template', true, true);

    expect(template_instance_test_themes($t)[0])->not->toHaveKey('local_head');
});

test('set_theme defaults colorscheme to the given value when the theme does not already set one', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/cs-theme', ['marker' => 'x']);
    $t = TemplateTestFactory::build();

    $t->set_theme($root, 'cs-theme', 'template', true, true, 'custom-scheme');

    expect($t->get_themeconf('colorscheme'))->toBe('custom-scheme');
});

test('set_theme preserves an already-set colorscheme instead of overwriting it', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/cs-theme2', ['marker' => 'x', 'colorscheme' => 'theme-defined']);
    $t = TemplateTestFactory::build();

    $t->set_theme($root, 'cs-theme2', 'template', true, true, 'custom-scheme');

    expect($t->get_themeconf('colorscheme'))->toBe('theme-defined');
});

test('set_theme merges themeconf directly into the flat "themeconf" template var, not nested under an index', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/merge-theme', ['marker' => 'flat-merge-check']);
    $t = TemplateTestFactory::build();

    $t->set_theme($root, 'merge-theme', 'template');

    $tc = template_instance_test_assoc($t->get_template_vars('themeconf'));
    expect($tc['marker'] ?? null)->toBe('flat-merge-check')
        ->and($tc)->not->toHaveKey(0);
});

// --- set_template_dir ----------------------------------------------------

test('set_template_dir does not recompute compile_id once already set', function (): void {
    $t = TemplateTestFactory::build();
    $before = $t->smarty->compile_id;

    $t->set_template_dir(CurrentPathsTestFactory::get()->root . '/some/other/dir');

    expect($t->smarty->compile_id)->toBe($before);
});

test('set_template_dir salts compile_id using the resolved realpath when the dir exists', function (): void {
    // Default construction calls set_template_dir($root) with $root='.'
    // (the constructor's own default), not CurrentPaths -- '.' resolves
    // relative to the test runner's cwd, which is a real, existing dir.
    $t = TemplateTestFactory::build();

    $expected = base_convert(hash('crc32b', '1' . realpath('.')), 16, 36);

    expect($t->smarty->compile_id)->toBe($expected);
});

test('set_template_dir salts compile_id with the raw dir string when realpath cannot resolve it', function (): void {
    $bogusDir = '/definitely/does/not/exist/' . bin2hex(random_bytes(4));
    $t = TemplateTestFactory::build($bogusDir);

    $expected = base_convert(hash('crc32b', '1' . $bogusDir), 16, 36);

    expect($t->smarty->compile_id)->toBe($expected);
});

// --- get_template_dir / get_themeconf / themeConf ----------------------

test('get_template_dir returns an empty string when Smarty has no template dir set', function (): void {
    $t = TemplateTestFactory::build();
    $t->smarty->setTemplateDir([]);

    expect($t->get_template_dir())->toBe('');
});

test('get_template_dir reads index 0 specifically, not any other index', function (): void {
    $t = TemplateTestFactory::build();
    $t->smarty->setTemplateDir([]);
    $t->smarty->addTemplateDir('/first/dir');
    $t->smarty->addTemplateDir('/second/dir');

    expect($t->get_template_dir())->toBe('/first/dir/');
});

test('get_themeconf returns an empty string when no themeconf var has been assigned', function (): void {
    $t = TemplateTestFactory::build();

    expect($t->get_themeconf('anything'))->toBe('');
});

test('get_themeconf returns the raw (possibly non-string) value from an assigned themeconf array', function (): void {
    $t = TemplateTestFactory::build();
    $t->smarty->assign('themeconf', ['label' => 'Dark', 'depth' => 3]);

    expect($t->get_themeconf('label'))->toBe('Dark')
        ->and($t->get_themeconf('depth'))->toBe(3)
        ->and($t->get_themeconf('missing'))->toBe('');
});

test('themeConf narrows a non-string themeconf value down to an empty string', function (): void {
    $t = TemplateTestFactory::build();
    $t->smarty->assign('themeconf', ['label' => 'Dark', 'depth' => 3]);

    expect($t->themeConf('label'))->toBe('Dark')
        ->and($t->themeConf('depth'))->toBe('');
});

// --- set_filename / set_extent / set_extents / get_extent --------------

test('set_filename delegates to set_filenames for a single handle', function (): void {
    $t = TemplateTestFactory::build();

    expect($t->set_filename('tail', 'footer.tpl'))->toBeTrue();
    expect($t->files['tail'])->toBe('footer.tpl');
});

test('set_filenames unsets an already-registered handle when its mapped filename is explicitly null', function (): void {
    $t = TemplateTestFactory::build();
    $t->set_filename('tail', 'footer.tpl');

    $result = $t->set_filenames(['tail' => null]);

    expect($result)->toBeTrue()
        ->and($t->files)->not->toHaveKey('tail');
});

test('set_extents returns false for a non-array argument', function (): void {
    $t = TemplateTestFactory::build();

    expect($t->set_extents('not-an-array'))->toBeFalse();
});

test('set_extents returns false when an array value has a non-string, non-int handle', function (): void {
    $t = TemplateTestFactory::build();

    expect($t->set_extents(['file.php' => [null, 'N/A', 'N/A']]))->toBeFalse();
});

test('set_extents returns false when a value is neither an array nor a string', function (): void {
    $t = TemplateTestFactory::build();

    expect($t->set_extents(['file.php' => 42]))->toBeFalse();
});

test('set_extent accepts the string-shorthand form and registers a real matching file', function (): void {
    $t = TemplateTestFactory::build();
    $extDir = CurrentPathsTestFactory::get()->root . '/ext/';
    mkdir($extDir, 0o777, true);
    file_put_contents($extDir . 'myfile.tpl', 'hello');

    expect($t->set_extent('myfile.tpl', 'myhandle', $extDir))->toBeTrue();
    expect($t->get_extent('original.tpl', 'myhandle'))->toBe(realpath($extDir . 'myfile.tpl'));
});

test('set_extent overwrites an already-registered handle when overwrite is true (the default)', function (): void {
    $t = TemplateTestFactory::build();
    $extDir = CurrentPathsTestFactory::get()->root . '/ext/';
    mkdir($extDir, 0o777, true);
    file_put_contents($extDir . 'first.tpl', 'a');
    file_put_contents($extDir . 'second.tpl', 'b');

    $t->set_extent('first.tpl', 'myhandle', $extDir);
    $t->set_extent('second.tpl', 'myhandle', $extDir, true);

    expect($t->get_extent('original.tpl', 'myhandle'))->toBe(realpath($extDir . 'second.tpl'));
});

test('set_extent registers a brand-new handle even when overwrite is false (nothing to protect yet)', function (): void {
    $t = TemplateTestFactory::build();
    $extDir = CurrentPathsTestFactory::get()->root . '/ext/';
    mkdir($extDir, 0o777, true);
    file_put_contents($extDir . 'first.tpl', 'a');

    $t->set_extent('first.tpl', 'brand-new-handle', $extDir, false);

    expect($t->get_extent('orig.tpl', 'brand-new-handle'))->toBe(realpath($extDir . 'first.tpl'));
});

test('set_extents (array form) registers a handle when handle/param/theme all read from their correct array indices', function (): void {
    $t = TemplateTestFactory::build();
    $dir = CurrentPathsTestFactory::get()->root . '/ext/';
    mkdir($dir, 0o777, true);
    file_put_contents($dir . 'file.tpl', 'x');
    $savedGet = $_GET;
    $_GET = ['/mypath' => '1'];

    try {
        $result = $t->set_extents(['file.tpl' => ['myhandle', 'mypath', 'mytheme']], $dir, true, 'mytheme');
    } finally {
        $_GET = $savedGet;
    }

    expect($result)->toBeTrue()
        ->and($t->get_extent('orig.tpl', 'myhandle'))->toBe(realpath($dir . 'file.tpl'));
});

test('set_extents param match requires a literal "/" separator before the GET key substring', function (): void {
    $t = TemplateTestFactory::build();
    $dir = CurrentPathsTestFactory::get()->root . '/ext/';
    mkdir($dir, 0o777, true);
    file_put_contents($dir . 'file.tpl', 'x');
    $savedGet = $_GET;
    // Contains 'mypath' as a raw substring, but never preceded by '/'.
    $_GET = ['xmypath' => '1'];

    try {
        $t->set_extents(['file.tpl' => ['myhandle', 'mypath', 'N/A']], $dir, true, 'N/A');
    } finally {
        $_GET = $savedGet;
    }

    expect($t->get_extent('orig.tpl', 'myhandle'))->toBe('orig.tpl');
});

test('set_extents param match requires the full param substring, not just any "/" in the GET keys', function (): void {
    $t = TemplateTestFactory::build();
    $dir = CurrentPathsTestFactory::get()->root . '/ext/';
    mkdir($dir, 0o777, true);
    file_put_contents($dir . 'file.tpl', 'x');
    $savedGet = $_GET;
    $_GET = ['/otherstuff' => '1'];

    try {
        $t->set_extents(['file.tpl' => ['myhandle', 'mypath', 'N/A']], $dir, true, 'N/A');
    } finally {
        $_GET = $savedGet;
    }

    expect($t->get_extent('orig.tpl', 'myhandle'))->toBe('orig.tpl');
});

test('set_extents registers when theme matches the passed theme exactly, not only via the N/A escape hatch', function (): void {
    $t = TemplateTestFactory::build();
    $dir = CurrentPathsTestFactory::get()->root . '/ext/';
    mkdir($dir, 0o777, true);
    file_put_contents($dir . 'file.tpl', 'x');

    $result = $t->set_extents(['file.tpl' => ['myhandle', 'N/A', 'exact-theme']], $dir, true, 'exact-theme');

    expect($result)->toBeTrue()
        ->and($t->get_extent('orig.tpl', 'myhandle'))->toBe(realpath($dir . 'file.tpl'));
});

test('set_extents does not register when theme matches neither the passed theme nor N/A', function (): void {
    $t = TemplateTestFactory::build();
    $dir = CurrentPathsTestFactory::get()->root . '/ext/';
    mkdir($dir, 0o777, true);
    file_put_contents($dir . 'file.tpl', 'x');

    $t->set_extents(['file.tpl' => ['myhandle', 'N/A', 'some-other-theme']], $dir, true, 'different-theme');

    expect($t->get_extent('orig.tpl', 'myhandle'))->toBe('orig.tpl');
});

test('get_extent returns the given filename unchanged when no extent is registered for the handle', function (): void {
    $t = TemplateTestFactory::build();

    expect($t->get_extent('plain.tpl', 'unregistered'))->toBe('plain.tpl');
});

test('set_extents checks file_exists on the full dir+filename concatenation, not on a bare dir prefix alone', function (): void {
    // $dir here is not itself an existing path (only $dir . $filename is) --
    // a real directory (as every other set_extents fixture in this file
    // uses) would already satisfy file_exists($dir) on its own, since
    // file_exists() is true for directories too, making it impossible to
    // tell a ConcatRemoveRight mutation (file_exists($dir) alone) apart
    // from the real file_exists($dir . $filename).
    $t = TemplateTestFactory::build();
    $dir = CurrentPathsTestFactory::get()->root . '/pfx-';
    file_put_contents(CurrentPathsTestFactory::get()->root . '/pfx-real.tpl', 'x');

    $result = $t->set_extents(['real.tpl' => ['myhandle', 'N/A', 'N/A']], $dir, true, 'N/A');

    expect($result)->toBeTrue()
        ->and($t->get_extent('orig.tpl', 'myhandle'))->toBe(realpath(CurrentPathsTestFactory::get()->root . '/pfx-real.tpl'));
});

test('set_extents does not register a handle when realpath fails despite file_exists succeeding', function (): void {
    // realpath() never resolves stream-wrapped (non-local) paths, unlike
    // file_exists() -- a fake url_stat() reporting a real file makes
    // file_exists() true while realpath() on that same path stays
    // unconditionally false, isolating the `$real_path !== false` guard
    // from the file_exists() check just above it.
    $t = TemplateTestFactory::build();
    $scheme = 'pwgtestextents' . bin2hex(random_bytes(4));
    stream_wrapper_register($scheme, TemplateInstanceTestFakeStatStreamWrapper::class);

    try {
        $result = $t->set_extents(['fake.tpl' => ['myhandle', 'N/A', 'N/A']], $scheme . '://', true, 'N/A');
    } finally {
        stream_wrapper_unregister($scheme);
    }

    expect($result)->toBeTrue()
        ->and($t->get_extent('orig.tpl', 'myhandle'))->toBe('orig.tpl');
});

// --- assign_var_from_handle / clear_assign --------------------------------

test('assign_var_from_handle assigns the parsed handle output (returned, not echoed) and returns true', function (): void {
    $t = TemplateTestFactory::build();
    $tplDir = CurrentPathsTestFactory::get()->root . '/tpl/';
    mkdir($tplDir, 0o777, true);
    file_put_contents($tplDir . 'partial.tpl', 'Hello {$name}');
    $t->set_template_dir($tplDir);
    $t->set_filename('partial', 'partial.tpl');
    $t->smarty->assign('name', 'World');

    $result = $t->assign_var_from_handle('greeting', 'partial');

    // assign_var_from_handle()'s only return statement is a hardcoded
    // `return true;`, so PHPStan can already prove this -- but the return
    // value is part of this test's own stated contract (see its name).
    // @phpstan-ignore pest.expectation.redundant
    expect($result)->toBeTrue()
        ->and($t->get_template_vars('greeting'))->toBe('Hello World');
});

test('clear_assign removes a previously assigned template variable', function (): void {
    $t = TemplateTestFactory::build();
    $t->smarty->assign('foo', 'bar');

    $t->clear_assign('foo');

    expect($t->get_template_vars('foo'))->toBeNull();
});

// --- p() ---------------------------------------------------------------

test('p flushes the output buffer, then appends a working Smarty debug console when template debugging is on', function (): void {
    // Smarty\Debug::display_debug() (vendor/smarty/smarty/src/Debug.php)
    // unconditionally calls $obj->getSource() -- only Smarty\Template
    // implements that method, so p() passes it a throwaway 'string:'
    // resource template rather than the bare $this->smarty engine (which
    // has no getSource() method and would throw an Error).
    CurrentConfigTestFactory::get()->debugTemplate = true;
    $t = TemplateTestFactory::build();
    $t->output = 'body-output';

    ob_start();
    $t->p();
    $output = ob_get_clean();

    expect($output)->toStartWith('body-output')
        ->and($output)->toContain('Smarty Debug Console')
        ->and($t->get_template_vars('AAAA_DEBUG_TOTAL_TIME__'))->toBeString();
});

test('p does not attempt to build a debug console when template debugging is off', function (): void {
    CurrentConfigTestFactory::get()->debugTemplate = false;
    $t = TemplateTestFactory::build();
    $t->output = 'body-output';

    ob_start();
    $t->p();
    $output = ob_get_clean();

    expect($output)->toBe('body-output')
        ->and($t->get_template_vars('AAAA_DEBUG_TOTAL_TIME__'))->toBeNull();
});

test('p passes full=true to display_debug so the console targets the shared __Smarty__ window, not a per-call hash', function (): void {
    // Smarty\Debug::display_debug()'s own $full param feeds
    // `$displayMode = $debugging === 2 || !$full;`, which selects the
    // rendered targetWindow: '__Smarty__' when $full is true (our real
    // $this->smarty->debugging is a plain bool, never the int 2, so
    // `$debugging === 2` is always false here), or a per-call md5 hash
    // when $full is false -- debug.tpl renders it straight into
    // `window.open("", "console{$targetWindow}", ...)`.
    CurrentConfigTestFactory::get()->debugTemplate = true;
    $t = TemplateTestFactory::build();
    $t->output = 'body-output';

    ob_start();
    $t->p();
    $output = ob_get_clean();

    expect($output)->toContain('console__Smarty__');
});

// --- parse -----------------------------------------------------------------

test('parse assigns ROOT_URL and ROOT_PATH before compiling', function (): void {
    $t = TemplateTestFactory::build();
    $tplDir = CurrentPathsTestFactory::get()->root . '/tpl/';
    mkdir($tplDir, 0o777, true);
    file_put_contents($tplDir . 'x.tpl', 'x');
    $t->set_template_dir($tplDir);
    $t->set_filename('x', 'x.tpl');

    $t->parse('x', true);

    expect($t->get_template_vars('ROOT_PATH'))->toBe(CurrentPathsTestFactory::get()->root)
        ->and($t->get_template_vars('ROOT_URL'))->toBeString();
});

test('parse registers external filters before compiling (so they run) and unregisters them again afterward', function (): void {
    $t = TemplateTestFactory::build();
    $tplDir = CurrentPathsTestFactory::get()->root . '/tpl/';
    mkdir($tplDir, 0o777, true);
    file_put_contents($tplDir . 'x.tpl', 'hello');
    $t->set_template_dir($tplDir);
    $t->set_filename('x', 'x.tpl');
    $t->set_prefilter('x', 'template_instance_test_uppercase_prefilter');

    $result = $t->parse('x', true);

    expect($result)->toBe('HELLO')
        ->and(template_instance_test_smarty_pre_filters($t->smarty))->toHaveCount(1);
});

test('parse salts compile_id with the current lang code during compilation when cache-by-language is on', function (): void {
    CurrentConfigTestFactory::get()->compiledTemplateCacheLanguage = true;
    LangTestFactory::get()->setLangInfo(['code' => 'fr_FR']);
    $t = TemplateTestFactory::build();
    $tplDir = CurrentPathsTestFactory::get()->root . '/tpl/';
    mkdir($tplDir, 0o777, true);
    file_put_contents($tplDir . 'x.tpl', 'x');
    $t->set_template_dir($tplDir);
    $t->set_filename('x', 'x.tpl');
    $before = $t->smarty->compile_id;
    $captured = null;
    // Registered directly on Smarty (bypassing set_prefilter()'s own
    // external_filters/load_external_filters() salting) so $before isn't
    // polluted by a second, unrelated salt contribution.
    $t->smarty->registerFilter('pre', function (string $source, \Smarty\Template $template) use (&$captured): string {
        $captured = $template->compile_id;
        return $source;
    });

    $t->parse('x', true);

    // Exact match (not just a suffix check) so an accumulating .= vs an
    // overwriting = are distinguishable -- both would still end with
    // "_fr_FR", but only .= preserves $before as a real prefix.
    expect($captured)->toBe($before . '_fr_FR');
});

test('parse does not salt compile_id with a lang code when cache-by-language is off', function (): void {
    CurrentConfigTestFactory::get()->compiledTemplateCacheLanguage = false;
    LangTestFactory::get()->setLangInfo(['code' => 'fr_FR']);
    $t = TemplateTestFactory::build();
    $tplDir = CurrentPathsTestFactory::get()->root . '/tpl/';
    mkdir($tplDir, 0o777, true);
    file_put_contents($tplDir . 'x.tpl', 'x');
    $t->set_template_dir($tplDir);
    $t->set_filename('x', 'x.tpl');
    $captured = null;
    $t->set_prefilter('x', function (string $source, \Smarty\Template $template) use (&$captured): string {
        $captured = $template->compile_id;
        return $source;
    });

    $t->parse('x', true);

    expect($captured)->not->toEndWith('_fr_FR');
});

// --- concat --------------------------------------------------------------

test('concat appends to an existing string template variable', function (): void {
    $t = TemplateTestFactory::build();
    $t->concat('greeting', 'Hello ');
    $t->concat('greeting', 'World');

    expect($t->get_template_vars('greeting'))->toBe('Hello World');
});

test('concat treats a non-string existing value as an empty prefix', function (): void {
    $t = TemplateTestFactory::build();
    $t->smarty->assign('counter', 42);
    $t->concat('counter', 'suffix');

    expect($t->get_template_vars('counter'))->toBe('suffix');
});

// --- picture/index buttons ------------------------------------------------

test('parse_picture_buttons assigns registered buttons sorted by rank', function (): void {
    $t = TemplateTestFactory::build();
    $t->add_picture_button('<button-b>', 50);
    $t->add_picture_button('<button-a>', 10);

    $t->parse_picture_buttons();

    expect($t->get_template_vars('PLUGIN_PICTURE_BUTTONS'))->toBe(['<button-a>', '<button-b>']);
});

test('parse_picture_buttons does nothing when no button was ever registered', function (): void {
    $t = TemplateTestFactory::build();

    $t->parse_picture_buttons();

    expect($t->get_template_vars('PLUGIN_PICTURE_BUTTONS'))->toBeNull();
});

test('parse_index_buttons assigns registered buttons sorted by rank', function (): void {
    $t = TemplateTestFactory::build();
    $t->add_index_button('<index-b>', 99);
    $t->add_index_button('<index-a>', 1);

    $t->parse_index_buttons();

    expect($t->get_template_vars('PLUGIN_INDEX_BUTTONS'))->toBe(['<index-a>', '<index-b>']);
});

// --- prefilter/postfilter/outputfilter registration -----------------------

test('set_prefilter registers callbacks under their weight, kept sorted ascending', function (): void {
    $t = TemplateTestFactory::build();
    $t->set_prefilter('tail', 'strtoupper', 60);
    $t->set_prefilter('tail', 'strtolower', 10);

    expect(array_keys($t->external_filters['tail']))->toBe([10, 60]);
    expect($t->external_filters['tail'][10][0])->toBe(['pre', 'strtolower']);
});

test('set_postfilter registers a post-type filter entry', function (): void {
    $t = TemplateTestFactory::build();
    $t->set_postfilter('tail', 'strtoupper', 30);

    expect($t->external_filters['tail'][30][0])->toBe(['post', 'strtoupper']);
});

test('set_postfilter keeps registered weights sorted ascending', function (): void {
    $t = TemplateTestFactory::build();
    $t->set_postfilter('tail', 'strtoupper', 60);
    $t->set_postfilter('tail', 'strtolower', 10);

    expect(array_keys($t->external_filters['tail']))->toBe([10, 60]);
});

test('set_outputfilter registers an output-type filter entry', function (): void {
    $t = TemplateTestFactory::build();
    $t->set_outputfilter('tail', 'strtoupper', 40);

    expect($t->external_filters['tail'][40][0])->toBe(['output', 'strtoupper']);
});

test('set_outputfilter keeps registered weights sorted ascending', function (): void {
    $t = TemplateTestFactory::build();
    $t->set_outputfilter('tail', 'strtoupper', 60);
    $t->set_outputfilter('tail', 'strtolower', 10);

    expect(array_keys($t->external_filters['tail']))->toBe([10, 60]);
});

test('load_external_filters registers every filter with Smarty and salts the compile_id with its type+callback identity', function (): void {
    $t = TemplateTestFactory::build();
    $t->set_prefilter('tail', 'strtoupper');
    $before = $t->smarty->compile_id;

    $t->load_external_filters('tail');

    $expected = $before . '.' . base_convert(hash('crc32b', 'prestrtoupper'), 16, 36);
    expect($t->smarty->compile_id)->toBe($expected);
    $t->unload_external_filters('tail');
});

test('load_external_filters accumulates the type+callback identity across multiple registered filters, not just the last one', function (): void {
    $t = TemplateTestFactory::build();
    $t->set_prefilter('tail', 'strtoupper', 10);
    $t->set_prefilter('tail', 'strtolower', 20);
    $before = $t->smarty->compile_id;

    $t->load_external_filters('tail');

    $expected = $before . '.' . base_convert(hash('crc32b', 'prestrtoupperprestrtolower'), 16, 36);
    expect($t->smarty->compile_id)->toBe($expected);
    $t->unload_external_filters('tail');
});

test('load_external_filters derives the callback_key from the debug type when the callback is neither array nor string', function (): void {
    $t = TemplateTestFactory::build();
    $t->set_prefilter('tail', static fn (string $s): string => $s);
    $before = $t->smarty->compile_id;

    $t->load_external_filters('tail');

    $expected = $before . '.' . base_convert(hash('crc32b', 'preClosure'), 16, 36);
    expect($t->smarty->compile_id)->toBe($expected);
    $t->unload_external_filters('tail');
});

test('load_external_filters derives the callback_key from an [object, method] array callback, joining each element\'s own string (or debug-type fallback)', function (): void {
    $t = TemplateTestFactory::build();
    // A real, valid callable ([$t, 'get_extent']) -- Smarty's own
    // registerFilter() calls is_callable() and throws otherwise. The
    // object element (not a string) exercises array_map()'s
    // get_debug_type() fallback; the 'get_extent' element exercises its
    // is_string() branch -- both sides of the same ternary in one call.
    $t->set_prefilter('tail', [$t, 'get_extent']);
    $before = $t->smarty->compile_id;

    $t->load_external_filters('tail');

    $expected = $before . '.' . base_convert(hash('crc32b', 'pre' . Template::class . 'get_extent'), 16, 36);
    expect($t->smarty->compile_id)->toBe($expected);
    $t->unload_external_filters('tail');
});

test('load_external_filters is a no-op for a handle with no registered filters', function (): void {
    $t = TemplateTestFactory::build();
    $before = $t->smarty->compile_id;

    $t->load_external_filters('untouched-handle');

    expect($t->smarty->compile_id)->toBe($before);
});

test('unload_external_filters unregisters every filter across every registered weight, not just one', function (): void {
    $t = TemplateTestFactory::build();
    $t->set_prefilter('tail', 'template_instance_test_uppercase_prefilter', 10);
    $t->set_prefilter('tail', 'template_instance_test_lowercase_prefilter', 20);
    $t->load_external_filters('tail');
    expect(template_instance_test_smarty_pre_filters($t->smarty))->toHaveCount(3);

    $t->unload_external_filters('tail');

    expect(template_instance_test_smarty_pre_filters($t->smarty))->toHaveCount(1);
});

// --- parse(): missing handle ------------------------------------------------

test('parse fatal-errors for a handle with no registered filename', function (): void {
    $this->expectErrorLog();
    $t = TemplateTestFactory::build();

    set_error_handler(static fn (): bool => true);
    try {
        $t->parse('never-set-filename');
    } finally {
        restore_error_handler();
    }
})->throws(ResponseReadyException::class);

// --- func_combine_script / func_get_combined_scripts -----------------------

/**
 * func_combine_script()/func_get_combined_scripts() log via
 * $this->errorCollector->recordFatal() (a real required constructor
 * collaborator; TemplateTestFactory::build() resolves the same
 * container-shared instance when Kernel is booted) -- resolved here the
 * same way, so drain() reads what Template's own call just wrote instead
 * of a disconnected fresh instance.
 */
function templateInstanceTestErrorCollector(): ErrorCollector
{
    $errorCollector = Kernel::container()->get(ErrorCollector::class);
    if (! $errorCollector instanceof ErrorCollector) {
        throw new LogicException('Container returned an unexpected type for ' . ErrorCollector::class);
    }

    return $errorCollector;
}

test('func_combine_script trigger_errors when id is missing', function (): void {
    // func_combine_script() logs via $this->errorCollector->recordFatal()
    // (not trigger_error(E_USER_ERROR), deprecated as of PHP 8.4 -- see
    // HtmlService::fatalError()'s own docblock) and simply returns, no
    // exception thrown -- checked directly via drain() instead of a
    // throwaway set_error_handler().
    $this->expectErrorLog();
    $t = TemplateTestFactory::build();
    templateInstanceTestErrorCollector()->drain();

    $t->func_combine_script([]);

    $collected = templateInstanceTestErrorCollector()->drain();
    expect($collected)->toBe(["[ERROR] combine_script: missing 'id' parameter"]);
});

test('func_combine_script requires id to be a string even when the key is set', function (): void {
    $this->expectErrorLog();
    $t = TemplateTestFactory::build();
    templateInstanceTestErrorCollector()->drain();

    $t->func_combine_script(['id' => 42, 'path' => 'x.js']);

    $collected = templateInstanceTestErrorCollector()->drain();
    expect($collected)->toBe(["[ERROR] combine_script: missing 'id' parameter"]);
});

test('func_combine_script trigger_errors for an invalid load value', function (): void {
    $this->expectErrorLog();
    $t = TemplateTestFactory::build();
    templateInstanceTestErrorCollector()->drain();

    $t->func_combine_script(['id' => 'x', 'load' => 'bogus']);

    $collected = templateInstanceTestErrorCollector()->drain();
    expect($collected)->toBe(["[ERROR] combine_script: invalid 'load' parameter"]);
});

test('func_combine_script maps load="footer" to load_mode 1', function (): void {
    $t = TemplateTestFactory::build();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'load' => 'footer']);

    expect($t->scriptLoader->get_all()['x']->load_mode)->toBe(1);
});

test('func_combine_script maps load="async" to load_mode 2', function (): void {
    $t = TemplateTestFactory::build();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'load' => 'async']);

    expect($t->scriptLoader->get_all()['x']->load_mode)->toBe(2);
});

test('func_combine_script defaults load_mode to 0 when no load param is given', function (): void {
    $t = TemplateTestFactory::build();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js']);

    expect($t->scriptLoader->get_all()['x']->load_mode)->toBe(0);
});

test('func_combine_script explodes a real comma-separated require string', function (): void {
    $t = TemplateTestFactory::build();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'require' => 'a,b']);

    expect($t->scriptLoader->get_all()['x']->precedents)->toBe(['a', 'b']);
});

test('func_combine_script casts a non-string scalar require to a string before exploding', function (): void {
    $t = TemplateTestFactory::build();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'require' => 5]);

    expect($t->scriptLoader->get_all()['x']->precedents)->toBe(['5']);
});

test('func_combine_script treats a missing require key as no requirements', function (): void {
    $t = TemplateTestFactory::build();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js']);

    expect($t->scriptLoader->get_all()['x']->precedents)->toBe([]);
});

test('func_combine_script treats require=0 (int) as no requirements', function (): void {
    $t = TemplateTestFactory::build();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'require' => 0]);

    expect($t->scriptLoader->get_all()['x']->precedents)->toBe([]);
});

test('func_combine_script treats require="0" (string) as no requirements', function (): void {
    $t = TemplateTestFactory::build();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'require' => '0']);

    expect($t->scriptLoader->get_all()['x']->precedents)->toBe([]);
});

test('func_combine_script treats require="" (empty string) as no requirements', function (): void {
    $t = TemplateTestFactory::build();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'require' => '']);

    expect($t->scriptLoader->get_all()['x']->precedents)->toBe([]);
});

test('func_combine_script treats require=false as no requirements', function (): void {
    $t = TemplateTestFactory::build();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'require' => false]);

    expect($t->scriptLoader->get_all()['x']->precedents)->toBe([]);
});

test('func_combine_script treats a non-scalar require array as no requirements', function (): void {
    $t = TemplateTestFactory::build();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'require' => [1, 2, 3]]);

    expect($t->scriptLoader->get_all()['x']->precedents)->toBe([]);
});

test('func_combine_script discards a non-string path', function (): void {
    $t = TemplateTestFactory::build();

    $t->func_combine_script(['id' => 'x', 'path' => 42]);

    expect($t->scriptLoader->get_all()['x']->path)->toBeNull();
});

test('func_combine_script keeps a real string path', function (): void {
    $t = TemplateTestFactory::build();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js']);

    expect($t->scriptLoader->get_all()['x']->path)->toBe('x.js');
});

test('func_combine_script defaults version to "0" when missing', function (): void {
    $t = TemplateTestFactory::build();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js']);

    expect($t->scriptLoader->get_all()['x']->version)->toBe('0');
});

test('func_combine_script falls back to version "0" for a non-string version', function (): void {
    $t = TemplateTestFactory::build();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'version' => 7]);

    expect($t->scriptLoader->get_all()['x']->version)->toBe('0');
});

test('func_combine_script keeps a real string version', function (): void {
    $t = TemplateTestFactory::build();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'version' => '3.2']);

    expect($t->scriptLoader->get_all()['x']->version)->toBe('3.2');
});

test('func_combine_script defaults is_template to false when the template param is missing', function (): void {
    $t = TemplateTestFactory::build();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js']);

    expect($t->scriptLoader->get_all()['x']->is_template)->toBeFalse();
});

test('func_combine_script sets is_template to true when the template param is truthy', function (): void {
    $t = TemplateTestFactory::build();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'template' => true]);

    expect($t->scriptLoader->get_all()['x']->is_template)->toBeTrue();
});

test('func_combine_script casts a non-bool truthy template value to a real bool before storing', function (): void {
    // ScriptLoader::add()'s $is_template param is natively typed `bool`,
    // and this file (like Template.php itself) runs under strict_types=1
    // -- without func_combine_script()'s own (bool) cast, forwarding the
    // raw int 1 straight through would throw a TypeError instead of
    // quietly coercing, since strict_types disallows int->bool coercion.
    $t = TemplateTestFactory::build();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'template' => 1]);

    expect($t->scriptLoader->get_all()['x']->is_template)->toBeTrue();
});

test('func_get_combined_scripts trigger_errors when load is missing', function (): void {
    $this->expectErrorLog();
    $t = TemplateTestFactory::build();
    templateInstanceTestErrorCollector()->drain();

    // The fatal signal ($this->errorCollector->recordFatal(), no exception) falls
    // through to the very next line, which reads the still-missing
    // $params['load'] key directly (no isset()) -- a real "Undefined
    // array key" E_WARNING this handler must still absorb, confirmed live.
    $caught = [];
    set_error_handler(static function (int $errno, string $errstr) use (&$caught): bool {
        $caught[] = $errstr;
        return true;
    });
    try {
        $result = $t->func_get_combined_scripts([]);
    } finally {
        restore_error_handler();
    }

    $collected = templateInstanceTestErrorCollector()->drain();
    expect($collected)->toBe(["[ERROR] get_combined_scripts: missing 'load' parameter"]);
    expect($caught)->toContain('Undefined array key "load"');
    // $params['load'] === 'header' is false for the missing/null case, so
    // it still falls through to the footer-scripts branch.
    expect($result)->toBe('');
});

test('func_get_combined_scripts returns the combined-scripts placeholder for the header load', function (): void {
    $t = TemplateTestFactory::build();

    expect($t->func_get_combined_scripts(['load' => 'header']))->toBe(Template::COMBINED_SCRIPTS_TAG);
});

test('func_get_combined_scripts renders sync footer scripts from get_footer_scripts()[0] as plain script tags', function (): void {
    // func_combine_script() never lets a script's version become the
    // literal false (unlike func_combine_css()) -- it always falls back to
    // a string, so make_script_src() always appends a "?v..." suffix here.
    // Exact match (not toContain) so positional mutations (dropping or
    // reordering the surrounding markup) are distinguishable too.
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/sync.js', 'console.log(1);');
    $t->func_combine_script(['id' => 'sync-script', 'path' => 'sync.js', 'load' => 'footer']);

    $result = $t->func_get_combined_scripts(['load' => 'footer']);

    expect($result)->toBe('<script type="text/javascript" src="sync.js?v' . AppInfo::VERSION . '"></script>');
});

test('func_get_combined_scripts renders async footer scripts from get_footer_scripts()[1] via a dynamic script element', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/async.js', 'console.log(1);');
    $t->func_combine_script(['id' => 'async-script', 'path' => 'async.js', 'load' => 'async']);

    $result = $t->func_get_combined_scripts(['load' => 'footer']);

    $src = 'async.js?v' . AppInfo::VERSION;
    $expected = '<script type="text/javascript">' . "\n"
        . "(function() {\nvar s,after = document.getElementsByTagName('script')[document.getElementsByTagName('script').length-1];" . "\n"
        . "s=document.createElement('script'); s.type='text/javascript'; s.async=true; s.src='{$src}';" . "\n"
        . 'after = after.parentNode.insertBefore(s, after);' . "\n"
        . '})();' . "\n"
        . '</script>';
    expect($result)->toBe($expected);
});

test('func_get_combined_scripts prefixes the root URL onto the script src, in the correct order', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/sync.js', 'console.log(1);');
    $t->func_combine_script(['id' => 'sync-script', 'path' => 'sync.js', 'load' => 'footer']);
    template_instance_test_root_path_override()->push('http://example.test/root/');
    try {
        $result = $t->func_get_combined_scripts(['load' => 'footer']);
    } finally {
        template_instance_test_root_path_override()->reset();
    }

    expect($result)->toBe('<script type="text/javascript" src="http://example.test/root/sync.js?v' . AppInfo::VERSION . '"></script>');
});

test('func_get_combined_scripts omits the version query string entirely for a combined (version=false) script', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/a.js', 'console.log("a");');
    file_put_contents(CurrentPathsTestFactory::get()->root . '/b.js', 'console.log("b");');
    $t->func_combine_script(['id' => 'a', 'path' => 'a.js', 'load' => 'footer']);
    $t->func_combine_script(['id' => 'b', 'path' => 'b.js', 'load' => 'footer']);

    $result = $t->func_get_combined_scripts(['load' => 'footer']);

    expect($result)->toContain('<script type="text/javascript" src="')
        ->and($result)->not->toContain('?v');
});

test('make_script_src (via func_get_combined_scripts) uses a remote script\'s own path verbatim, with no root URL prefix or version suffix', function (): void {
    $t = TemplateTestFactory::build();
    $t->func_combine_script(['id' => 'remote-script', 'path' => 'https://cdn.example.com/foo.js', 'load' => 'footer']);

    $result = $t->func_get_combined_scripts(['load' => 'footer']);

    expect($result)->toBe('<script type="text/javascript" src="https://cdn.example.com/foo.js"></script>');
});

test('make_script_src (via func_get_combined_scripts) throws when a combined_script listener returns something other than a CombinedScript instance', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/sync.js', 'console.log(1);');
    $t->func_combine_script(['id' => 'sync-script', 'path' => 'sync.js', 'load' => 'footer']);
    EventDispatcherTestFactory::get()->addEventHandler(CombinedScript::class, static fn (): int => 42);

    try {
        $t->func_get_combined_scripts(['load' => 'footer']);
    } finally {
        EventDispatcherTestFactory::get()->reset();
    }
})->throws(Error::class, 'must return an instance of');

// --- block_footer_script ----------------------------------------------------

test('block_footer_script registers an inline script once its own required script is already known', function (): void {
    $t = TemplateTestFactory::build();
    $t->func_combine_script(['id' => 'foo', 'path' => 'foo.js']);

    $t->block_footer_script(['require' => 'foo'], 'console.log(1);');

    expect($t->scriptLoader->inline_scripts)->toBe(['console.log(1);']);
});

test('block_footer_script does nothing on the opening-tag call (null content)', function (): void {
    $t = TemplateTestFactory::build();

    $t->block_footer_script([], null);

    expect($t->scriptLoader->inline_scripts)->toBe([]);
});

test('block_footer_script treats whitespace-only content as the opening-tag call (trims before checking emptiness)', function (): void {
    $t = TemplateTestFactory::build();

    $t->block_footer_script([], "   \n");

    expect($t->scriptLoader->inline_scripts)->toBe([]);
});

test('block_footer_script treats a missing require key as no requirements', function (): void {
    $t = TemplateTestFactory::build();

    $t->block_footer_script([], 'console.log(1);');

    expect($t->scriptLoader->inline_scripts)->toBe(['console.log(1);']);
});

test('block_footer_script treats every require sentinel value (0, "0", "", false, non-scalar array) as no requirements', function (): void {
    $t = TemplateTestFactory::build();

    foreach ([0, '0', '', false, [1, 2, 3]] as $sentinel) {
        $t->block_footer_script(['require' => $sentinel], 'console.log(1);');
    }

    expect($t->scriptLoader->inline_scripts)->toBe(array_fill(0, 5, 'console.log(1);'));
});

test('block_footer_script casts a non-string scalar require to a string before looking up the dependency', function (): void {
    $t = TemplateTestFactory::build();
    $t->func_combine_script(['id' => '5', 'path' => '5.js']);

    $t->block_footer_script(['require' => 5], 'console.log(1);');

    expect($t->scriptLoader->inline_scripts)->toBe(['console.log(1);']);
});

test('block_footer_script actually reads the require param (fatal-errors for an unknown required script)', function (): void {
    $this->expectErrorLog();
    $t = TemplateTestFactory::build();

    set_error_handler(static fn (): bool => true);
    try {
        $t->block_footer_script(['require' => 'totally-unknown-script-id'], 'console.log(1);');
    } finally {
        restore_error_handler();
    }
})->throws(ResponseReadyException::class);

// --- func_combine_css / finalizeOutput (via fetchOutput) --------------------

test('func_combine_css fatal-errors when path is missing', function (): void {
    $this->expectErrorLog();
    $t = TemplateTestFactory::build();

    set_error_handler(static fn (): bool => true);
    try {
        $t->func_combine_css([]);
    } finally {
        restore_error_handler();
    }
})->throws(ResponseReadyException::class);

test('func_combine_css fatal-errors for every path sentinel value (false, 0, "0", "", [])', function (): void {
    $this->expectErrorLog();
    $t = TemplateTestFactory::build();

    $caughtCount = 0;
    set_error_handler(static fn (): bool => true);
    try {
        foreach ([false, 0, '0', '', []] as $sentinel) {
            try {
                $t->func_combine_css(['path' => $sentinel]);
            } catch (ResponseReadyException) {
                $caughtCount++;
            }
        }
    } finally {
        restore_error_handler();
    }

    expect($caughtCount)->toBe(5);
});

test('func_combine_css fatal-errors when path is a non-string, non-sentinel value', function (): void {
    $this->expectErrorLog();
    $t = TemplateTestFactory::build();

    set_error_handler(static fn (): bool => true);
    try {
        $t->func_combine_css(['path' => 42]);
    } finally {
        restore_error_handler();
    }
})->throws(ResponseReadyException::class);

test('func_combine_css derives id from md5(path) when id is missing', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css']);

    expect(template_instance_test_cssloader_registered($t->cssLoader))->toHaveKey(md5('style.css'));
});

test('func_combine_css keeps a real string id when given', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css']);

    expect(template_instance_test_cssloader_registered($t->cssLoader))->toHaveKey('my-css');
});

test('func_combine_css falls back to md5(path) when id is a non-string value', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 42]);

    expect(template_instance_test_cssloader_registered($t->cssLoader))->toHaveKey(md5('style.css'));
});

test('func_combine_css defaults version to "0" when missing', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css']);

    expect(template_instance_test_cssloader_registered($t->cssLoader)['my-css']->version)->toBe('0');
});

test('func_combine_css keeps version=false as-is', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css', 'version' => false]);

    expect(template_instance_test_cssloader_registered($t->cssLoader)['my-css']->version)->toBeFalse();
});

test('func_combine_css falls back to version "0" for a non-string, non-false version', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css', 'version' => 7]);

    expect(template_instance_test_cssloader_registered($t->cssLoader)['my-css']->version)->toBe('0');
});

test('func_combine_css defaults order to 0 when missing', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css']);

    expect(template_instance_test_cssloader_registered($t->cssLoader)['my-css']->order)->toBe(0);
});

test('func_combine_css casts a real numeric order to an int', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css', 'order' => '5']);

    // CssLoader::add() itself computes order*1000+counter -- 5*1000+0 for
    // the first registration -- confirming func_combine_css forwarded a
    // real int 5, not the string "5" (int "5"*1000 vs string concatenation
    // would differ).
    expect(template_instance_test_cssloader_registered($t->cssLoader)['my-css']->order)->toBe(5000);
});

test('func_combine_css truncates a fractional numeric order string to an int', function (): void {
    // '5.7' * 1000 (no cast) would produce a float (5700.0); only a real
    // (int) cast first truncates to 5, giving 5*1000=5000 -- distinguishes
    // the cast from arithmetic auto-coercion, unlike a whole-number string.
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css', 'order' => '5.7']);

    expect(template_instance_test_cssloader_registered($t->cssLoader)['my-css']->order)->toBe(5000);
});

test('func_combine_css falls back to order 0 for a non-numeric order', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css', 'order' => 'not-numeric']);

    expect(template_instance_test_cssloader_registered($t->cssLoader)['my-css']->order)->toBe(0);
});

test('func_combine_css sets is_template to true when the template param is truthy', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css', 'template' => true]);

    expect(template_instance_test_cssloader_registered($t->cssLoader)['my-css']->is_template)->toBeTrue();
});

test('func_combine_css casts a non-bool truthy template value to a real bool before storing', function (): void {
    // Unlike ScriptLoader::add(), CssLoader::add()'s $is_template param is
    // untyped, so without func_combine_css()'s own (bool) cast the raw int
    // 1 would be stored as-is (int(1), not bool(true)) -- toBeTrue() is a
    // strict === true check, so it only passes when the cast really ran.
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css', 'template' => 1]);

    expect(template_instance_test_cssloader_registered($t->cssLoader)['my-css']->is_template)->toBeTrue();
});

test('func_combine_css defaults is_template to false when the template param is missing', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css']);

    expect(template_instance_test_cssloader_registered($t->cssLoader)['my-css']->is_template)->toBeFalse();
});

test('finalizeOutput appends a version query string for a truthy combined_css version', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');
    $t->func_combine_css(['path' => 'style.css', 'version' => '7']);
    $t->output = Template::COMBINED_CSS_TAG;

    $result = $t->fetchOutput();

    expect($result)->toBe('<link rel="stylesheet" type="text/css" href="style.css?v7">');
});

test('finalizeOutput throws when a combined_css listener returns something other than a CombinedCss instance', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');
    $t->func_combine_css(['path' => 'style.css', 'version' => '7']);
    $t->output = Template::COMBINED_CSS_TAG;
    EventDispatcherTestFactory::get()->addEventHandler(CombinedCss::class, static fn (): int => 42);

    $t->fetchOutput();
})->throws(Error::class, 'must return an instance of');

test('finalizeOutput does not append a version query string when combined_css version is exactly false', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');
    $t->func_combine_css(['path' => 'style.css', 'version' => false]);
    $t->output = Template::COMBINED_CSS_TAG;

    $result = $t->fetchOutput();

    expect($result)->toBe('<link rel="stylesheet" type="text/css" href="style.css">');
});

test('finalizeOutput builds the combined-css href by prefixing the root URL onto the combi path', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');
    template_instance_test_root_path_override()->push('http://example.test/root/');
    try {
        $t->func_combine_css(['path' => 'style.css', 'version' => false]);
        $t->output = Template::COMBINED_CSS_TAG;

        $result = $t->fetchOutput();
    } finally {
        template_instance_test_root_path_override()->reset();
    }

    expect($result)->toBe('<link rel="stylesheet" type="text/css" href="http://example.test/root/style.css">');
});

test('finalizeOutput clears the CSS loader so a second call does not re-emit already-flushed CSS', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');
    $t->func_combine_css(['path' => 'style.css', 'version' => false]);
    $t->output = Template::COMBINED_CSS_TAG;
    $first = $t->fetchOutput();

    $t->output = Template::COMBINED_CSS_TAG;
    $second = $t->fetchOutput();

    expect($first)->toContain('style.css')
        ->and($second)->not->toContain('style.css');
});

test('finalizeOutput does not reprocess the combined-scripts tag once did_head is already true', function (): void {
    $t = TemplateTestFactory::build();
    $t->scriptLoader->get_head_scripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()));
    $t->output = Template::COMBINED_SCRIPTS_TAG;

    $result = $t->fetchOutput();

    expect($result)->toBe(Template::COMBINED_SCRIPTS_TAG);
});

test('finalizeOutput injects head elements before </head> when the source contains that anchor', function (): void {
    $t = TemplateTestFactory::build();
    $t->block_html_head([], null);
    $t->block_html_head([], '<meta a>');
    $t->output = "<head>\n</head>\nbody";

    $result = $t->fetchOutput();

    expect($result)->toBe("<head>\n<meta a>\n</head>\nbody");
});

test('finalizeOutput injects the accumulated html_style before </head> even with no head elements registered', function (): void {
    $t = TemplateTestFactory::build();
    $t->block_html_style([], null);
    $t->block_html_style([], 'body{color:red}');
    $t->output = "<head>\n</head>\nbody";

    $result = $t->fetchOutput();

    expect($result)->toBe("<head>\n<style type=\"text/css\">\nbody{color:red}</style>\n</head>\nbody");
});

test('finalizeOutput does not touch </head> when no head elements or html_style were registered', function (): void {
    $t = TemplateTestFactory::build();
    $t->output = "<head>\n</head>\nbody";

    $result = $t->fetchOutput();

    expect($result)->toBe("<head>\n</head>\nbody");
});

test('finalizeOutput does not inject head elements when the source has no </head> anchor', function (): void {
    $t = TemplateTestFactory::build();
    $t->block_html_head([], null);
    $t->block_html_head([], '<meta a>');
    $t->output = 'no head tag here';

    $result = $t->fetchOutput();

    expect($result)->toBe('no head tag here');
});

test('finalizeOutput resets html_style after injecting it, so a second call does not reapply it', function (): void {
    $t = TemplateTestFactory::build();
    $t->block_html_style([], 'body{color:red}');
    $t->output = "<head>\n</head>\nfirst";
    $first = $t->fetchOutput();

    $t->output = "<head>\n</head>\nsecond";
    $second = $t->fetchOutput();

    // Exact match on $second (not just "doesn't contain the old value") --
    // an EmptyStringToNotEmpty mutation of the reset would leave html_style
    // as some OTHER non-empty sentinel, which would still re-trigger the
    // injection gate and inject a (different, but still present) <style>
    // tag into $second; a bare "not->toContain('color:red')" check
    // wouldn't notice that.
    expect($first)->toContain('color:red')
        ->and($second)->toBe("<head>\n</head>\nsecond");
});

test('finalizeOutput resets the output buffer to an empty string after flushing', function (): void {
    $t = TemplateTestFactory::build();
    $t->output = 'hello';

    $t->fetchOutput();

    expect($t->output)->toBe('');
});

// --- block_html_head / block_html_style --------------------------------------

test('block_html_head trims whitespace-only content so it is treated as the opening-tag call', function (): void {
    $t = TemplateTestFactory::build();

    $t->block_html_head([], "   \n");

    expect($t->html_head_elements)->toBe([]);
});

test('block_html_style trims whitespace-only content so it is treated as the opening-tag call', function (): void {
    $t = TemplateTestFactory::build();
    $t->block_html_style([], "   \n");
    $t->output = "<head>\n</head>\nbody";

    $result = $t->fetchOutput();

    expect($result)->toBe("<head>\n</head>\nbody");
});

test('block_html_style accumulates multiple registrations rather than overwriting', function (): void {
    $t = TemplateTestFactory::build();
    $t->block_html_style([], 'a{color:red}');
    $t->block_html_style([], 'b{color:blue}');
    $t->output = "<head>\n</head>\nbody";

    $result = $t->fetchOutput();

    expect($result)->toBe("<head>\n<style type=\"text/css\">\na{color:red}\nb{color:blue}</style>\n</head>\nbody");
});

// --- prefilter_local_css ----------------------------------------------------

test('prefilter_local_css injects a combine_css tag for a real theme-specific rules file', function (): void {
    mkdir(CurrentPathsTestFactory::get()->root . '/local/css', 0o777, true);
    file_put_contents(CurrentPathsTestFactory::get()->root . '/local/css/mytheme-rules.css', 'body{}');
    $t = TemplateTestFactory::build();
    $t->smarty->assign('themes', [['id' => 'mytheme'], ['id' => 'no-such-theme'], 'not-an-array', ['no-id' => true]]);

    $result = Template::prefilter_local_css('before {get_combined_css} after', $t->smarty, CurrentPathsTestFactory::get());

    expect($result)->toBe("before {combine_css path='local/css/mytheme-rules.css' order=10}\n{get_combined_css} after");
});

test('prefilter_local_css injects a combine_css tag for a real site-wide rules.css', function (): void {
    mkdir(CurrentPathsTestFactory::get()->root . '/local/css', 0o777, true);
    file_put_contents(CurrentPathsTestFactory::get()->root . '/local/css/rules.css', 'body{}');
    $t = TemplateTestFactory::build();

    $result = Template::prefilter_local_css('before {get_combined_css} after', $t->smarty, CurrentPathsTestFactory::get());

    expect($result)->toBe("before {combine_css path='local/css/rules.css' order=10}\n{get_combined_css} after");
});

test('prefilter_local_css leaves the source untouched when no local css files exist', function (): void {
    $t = TemplateTestFactory::build();

    $result = Template::prefilter_local_css('before {get_combined_css} after', $t->smarty, CurrentPathsTestFactory::get());

    expect($result)->toBe('before {get_combined_css} after');
});

test('prefilter_local_css continues past an invalid theme entry instead of stopping the whole loop', function (): void {
    mkdir(CurrentPathsTestFactory::get()->root . '/local/css', 0o777, true);
    file_put_contents(CurrentPathsTestFactory::get()->root . '/local/css/second-rules.css', 'body{}');
    $t = TemplateTestFactory::build();
    $t->smarty->assign('themes', ['not-an-array', ['id' => 'second']]);

    $result = Template::prefilter_local_css('before {get_combined_css} after', $t->smarty, CurrentPathsTestFactory::get());

    expect($result)->toBe("before {combine_css path='local/css/second-rules.css' order=10}\n{get_combined_css} after");
});

// --- load_themeconf ----------------------------------------------------------

test('load_themeconf returns an empty array for a theme directory that does not exist', function (): void {
    $t = TemplateTestFactory::build();

    expect($t->load_themeconf(CurrentPathsTestFactory::get()->root . '/no-such-theme-dir'))->toBe([]);
});

test('load_themeconf includes themeconf.inc.php, returns its $themeconf, and assigns its $theme_template_vars', function (): void {
    $t = TemplateTestFactory::build();
    $dir = CurrentPathsTestFactory::get()->root . '/theme-real';
    mkdir($dir, 0o777, true);
    file_put_contents(
        $dir . '/themeconf.inc.php',
        '<?php $themeconf = ["name" => "Real Theme"]; $theme_template_vars = ["theme_var" => "assigned-value"];'
    );

    $result = $t->load_themeconf($dir);

    // A bare AlwaysReturnNull mutation on the final `return $cached;`, or a
    // RemoveMethodCall dropping the ProcessCache::setStatic() a few lines above
    // it (which would leave the trailing ProcessCache::get() reading back
    // nothing), both collapse $result to null instead of the real array.
    expect($result)->toBe(['name' => 'Real Theme'])
        ->and($t->get_template_vars('theme_var'))->toBe('assigned-value');
});

test('load_themeconf caches per-directory: a second, different theme dir is not served the first dir\'s cached result', function (): void {
    // Kills a ConcatRemoveRight mutation on $cache_key ('themeconf:' . $dir
    // collapsing to the bare literal 'themeconf:'), which would make every
    // directory share one cache slot -- the second call below would then
    // wrongly return the first dir's already-cached themeconf.
    $t = TemplateTestFactory::build();
    $dirA = CurrentPathsTestFactory::get()->root . '/theme-a';
    $dirB = CurrentPathsTestFactory::get()->root . '/theme-b';
    mkdir($dirA, 0o777, true);
    mkdir($dirB, 0o777, true);
    file_put_contents($dirA . '/themeconf.inc.php', '<?php $themeconf = ["which" => "A"];');
    file_put_contents($dirB . '/themeconf.inc.php', '<?php $themeconf = ["which" => "B"];');

    $resultA = $t->load_themeconf($dirA);
    $resultB = $t->load_themeconf($dirB);

    expect($resultA)->toBe(['which' => 'A'])
        ->and($resultB)->toBe(['which' => 'B']);
});

test('load_themeconf caches under the exact "themeconf:" . $dir key shape, not a bare or reversed variant', function (): void {
    // Poisons the two other plausible cache-key shapes a mutated concat
    // could produce -- ConcatRemoveLeft (bare $dir, dropping the
    // 'themeconf:' prefix) and ConcatSwitchSides ($dir . 'themeconf:',
    // operands reversed) -- with a recognizable sentinel value each. If
    // load_themeconf() ever computed its cache key as either of those
    // variants, ProcessCache::has() would find one of these pre-seeded
    // entries and return its poisoned value instead of the real,
    // freshly-computed themeconf.
    $t = TemplateTestFactory::build();
    $dir = CurrentPathsTestFactory::get()->root . '/theme-format';
    mkdir($dir, 0o777, true);
    file_put_contents($dir . '/themeconf.inc.php', '<?php $themeconf = ["real" => true];');
    $realDir = (string) realpath($dir);
    $processCache = Kernel::container()->get(ProcessCache::class);
    if (! $processCache instanceof ProcessCache) {
        throw new LogicException('Container returned an unexpected type for ' . ProcessCache::class);
    }
    $processCache->set($realDir, ['poisoned' => 'bare-dir-key']);
    $processCache->set($realDir . 'themeconf:', ['poisoned' => 'switched-key']);

    $result = $t->load_themeconf($dir);

    expect($result)->toBe(['real' => true]);
});
