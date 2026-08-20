<?php

declare(strict_types=1);

use Latte\Runtime\Html;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Template\Css;
use Piwigo\Template\CssLoader;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateAdapter;
use Piwigo\Tests\Support\AdHocPageContext;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Piwigo\Url\RootPathOverride;

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
 * constructible Template (real filesystem template dir) but no DB:
 * TemplateTest.php's own docblock deliberately keeps to static,
 * instance-free logic, so every instance method below (the bulk of this
 * class's own gap) had zero coverage. Same "point CurrentPaths at a fresh
 * temp root" construction setup as PictureRateRendererTest.php's own
 * docblock. defineDerivative() is the one instance method that genuinely
 * needs a real DB (ImageStdParams::getCustom()'s own save() call) -- see
 * tests/Integration/TemplateDefineDerivativeTest.php instead.
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
 * Writes a minimal, real theme.json fixture -- exercised through a
 * genuine `json_decode()` (loadThemeconf()'s own real contract),
 * matching the schema-shaped fields every real themes/*\/theme.json in
 * this repo uses. Callers pass the same snake_case keys `Template::
 * loadThemeJson()`'s own returned `$themeconf` array uses (`parent`,
 * `use_standard_pages`, `load_parent_css`, `load_parent_local_head`,
 * `local_head`, `colorscheme`, `marker` -- the last one a test-only
 * stand-in written into the real `iconDir` field, since `loadThemeJson()`
 * only ever copies a fixed allowlist of known fields through, unlike the
 * old `include`-based mechanism's arbitrary-key passthrough; `iconDir`
 * is unclaimed by any of these tests' own real assertions) -- translated
 * here to the camelCase `theme.json` shape. Always carries a 'local_head'
 * key (defaulting to '') unless the caller overrides it -- setTheme()'s
 * own local_head branch reads $themeconf['local_head'] directly (not via
 * `??`) in its is_string() clause, so an actually-missing key would
 * trigger a real "Undefined array key" warning (failOnWarning=true) for
 * every setTheme() test that isn't specifically exercising that gap.
 *
 * @param array<string, mixed> $vars
 */
function template_instance_test_write_themeconf(string $dir, array $vars): void
{
    if (! is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }
    $vars += [
        'local_head' => '',
    ];

    $json = [];
    if (array_key_exists('marker', $vars)) {
        $json['iconDir'] = $vars['marker'];
    }
    if (array_key_exists('parent', $vars)) {
        $json['parent'] = $vars['parent'];
    }
    if (array_key_exists('use_standard_pages', $vars)) {
        $json['useStandardPages'] = $vars['use_standard_pages'];
    }
    if (array_key_exists('load_parent_css', $vars)) {
        $json['loadParentCss'] = $vars['load_parent_css'];
    }
    if (array_key_exists('load_parent_local_head', $vars)) {
        $json['loadParentLocalHead'] = $vars['load_parent_local_head'];
    }
    if ($vars['local_head'] !== '') {
        $json['localHead'] = $vars['local_head'];
    }
    if (array_key_exists('colorscheme', $vars)) {
        $json['colorscheme'] = $vars['colorscheme'];
    }

    file_put_contents(
        $dir . '/theme.json',
        json_encode($json, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
    );
}

/**
 * Narrows Template::getTemplateVars()'s mixed return down to the "list of
 * per-theme arrays" shape setTheme() itself always appends.
 *
 * @return list<array<string, mixed>>
 */
function template_instance_test_themes(Template $t): array
{
    $themes = $t->getTemplateVars('themes');
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
        $body = (string) $e->response()
            ->getBody();
    } finally {
        restore_error_handler();
        chmod(CurrentPathsTestFactory::get()->root, 0o755);
    }

    expect($body)
        ->not->toBeNull()
        ->and($body)
        ->not->toContain("#1\t");
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
        $body = (string) $e->response()
            ->getBody();
    } finally {
        restore_error_handler();
        chmod(CurrentPathsTestFactory::get()->root, 0o755);
        TranslatorTestFactory::get()->reset();
        LangTestFactory::get()->reset();
    }

    expect($body)
        ->not->toBeNull()
        ->and($body)
        ->toContain('<h1>CUSTOM-ADMIN-LANG-TITLE</h1>');
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

// --- constructor: pwg assign -------------------------------------------

test('constructor assigns the pwg template adapter', function (): void {
    $t = TemplateTestFactory::build();

    expect($t->getTemplateVars('pwg'))
        ->toBeInstanceOf(TemplateAdapter::class);
});

test('constructor sets templateDir to the raw root argument (root=".")', function (): void {
    // setTemplateDir() no longer resolves against a real filesystem path
    // (no more Smarty addTemplateDir()/realpath() normalization) -- it
    // just appends the raw string as-is, so the constructor's own
    // setTemplateDir($root) call (root='.', the constructor's own
    // default) leaves getTemplateDir() reading back the literal '.'.
    $t = TemplateTestFactory::build();

    expect($t->getTemplateDir())
        ->toBe('.');
});

test('constructor derives jquery_code and plupload_code from the lang code when not already set', function (): void {
    LangTestFactory::get()->setLangInfo([
        'code' => 'en-UK',
    ]);

    $t = TemplateTestFactory::build();

    $expected = [
        'code' => 'en-UK',
        'jquery_code' => 'en-UK',
        'plupload_code' => 'en_UK',
    ];
    expect(LangTestFactory::get()->langInfo())->toBe($expected)
        ->and($t->getTemplateVars('lang_info'))
        ->toBe($expected);
});

test('constructor never overwrites an already-present jquery_code, even when code is also set', function (): void {
    // Real gap: a LogicalAndToLogicalOr mutation on this guard's own `and`
    // (isset(code) and !isset(jquery_code)) only differs from the real
    // `or` once BOTH code and a pre-existing, different jquery_code are
    // present -- the sibling test above only ever sets code alone.
    LangTestFactory::get()->setLangInfo([
        'code' => 'en-UK',
        'jquery_code' => 'already-set',
    ]);

    TemplateTestFactory::build();

    expect(LangTestFactory::get()->langInfo()['jquery_code'])->toBe('already-set');
});

test('constructor skips deriving plupload_code when jquery_code is set but not a string, without throwing', function (): void {
    // Real gap: a LogicalAndToLogicalOr mutation on this guard's own first
    // `and` (isset(jquery_code) and is_string(jquery_code)) groups the
    // first two clauses into an `or` instead -- isset(jquery_code) alone
    // being true is enough to reach str_replace('-', '_', $jquery_code)
    // even when jquery_code isn't a string, which throws a TypeError
    // under strict_types. A non-string jquery_code proves the real `and`
    // (not `or`) is what prevents that call.
    LangTestFactory::get()->setLangInfo([
        'code' => 'en-UK',
        'jquery_code' => true,
    ]);

    TemplateTestFactory::build();

    expect(LangTestFactory::get()->langInfo())->not->toHaveKey('plupload_code');
});

// --- setTheme -----------------------------------------------------------

test('setTheme loads themeconf from exactly root/theme, joined with a literal slash', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/concat-theme', [
        'marker' => 'root-slash-theme',
    ]);
    $t = TemplateTestFactory::build();

    $t->setTheme($root, ThemeId::from('concat-theme'), 'template');

    expect($t->getThemeconf('icon_dir'))
        ->toBe('root-slash-theme');
});

test('setTheme recognizes every whitelisted auth-page basename for the standard-pages swap', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/wl-theme', [
        'marker' => 'not-swapped',
    ]);
    template_instance_test_write_themeconf($root . '/standard_pages', [
        'marker' => 'swapped',
    ]);
    CurrentConfigTestFactory::get()->useStandardPages = true;
    $saved = template_instance_test_save_server_keys();

    try {
        foreach (['identification', 'register', 'password', 'profile'] as $basename) {
            $_SERVER['SCRIPT_NAME'] = '/' . $basename . '.php';
            unset($_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);

            $t = TemplateTestFactory::build();
            $t->setTheme($root, ThemeId::from('wl-theme'), 'template');

            expect($t->getThemeconf('icon_dir'))
                ->toBe('swapped');
        }
    } finally {
        template_instance_test_restore_server_keys($saved);
    }
});

test('setTheme does not swap themes when the current page is not a whitelisted auth page', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/not-auth-theme', [
        'marker' => 'not-swapped',
    ]);
    template_instance_test_write_themeconf($root . '/standard_pages', [
        'marker' => 'swapped',
    ]);
    CurrentConfigTestFactory::get()->useStandardPages = true;
    $saved = template_instance_test_save_server_keys();
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    unset($_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);
    $t = TemplateTestFactory::build();

    try {
        $t->setTheme($root, ThemeId::from('not-auth-theme'), 'template');
    } finally {
        template_instance_test_restore_server_keys($saved);
    }

    expect($t->getThemeconf('icon_dir'))
        ->toBe('not-swapped');
});

test('setTheme never swaps away from the "default" theme itself even on a whitelisted auth page', function (): void {
    // Also proves the first `and` genuinely short-circuits the whole
    // condition (not an `or`): with theme==='default', an `or`-mutated
    // first join would let the (matching) auth-page clause alone drag the
    // whole condition true, still swapping away from 'default'.
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/default', [
        'marker' => 'default-marker',
    ]);
    template_instance_test_write_themeconf($root . '/standard_pages', [
        'marker' => 'swapped',
    ]);
    CurrentConfigTestFactory::get()->useStandardPages = true;
    $saved = template_instance_test_save_server_keys();
    $_SERVER['SCRIPT_NAME'] = '/identification.php';
    unset($_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);
    $t = TemplateTestFactory::build();

    try {
        $t->setTheme($root, ThemeId::from('default'), 'template');
    } finally {
        template_instance_test_restore_server_keys($saved);
    }

    expect($t->getThemeconf('icon_dir'))
        ->toBe('default-marker');
});

test('setTheme swaps themes when the theme itself opts into standard pages, even if the global config does not', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/opt-in-theme', [
        'marker' => 'not-swapped',
        'use_standard_pages' => true,
    ]);
    template_instance_test_write_themeconf($root . '/standard_pages', [
        'marker' => 'swapped',
    ]);
    CurrentConfigTestFactory::get()->useStandardPages = false;
    $saved = template_instance_test_save_server_keys();
    $_SERVER['SCRIPT_NAME'] = '/identification.php';
    unset($_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);
    $t = TemplateTestFactory::build();

    try {
        $t->setTheme($root, ThemeId::from('opt-in-theme'), 'template');
    } finally {
        template_instance_test_restore_server_keys($saved);
    }

    expect($t->getThemeconf('icon_dir'))
        ->toBe('swapped');
});

test('setTheme does not swap themes when neither the theme nor the global config opts into standard pages', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/opt-out-theme', [
        'marker' => 'not-swapped',
    ]);
    template_instance_test_write_themeconf($root . '/standard_pages', [
        'marker' => 'swapped',
    ]);
    CurrentConfigTestFactory::get()->useStandardPages = false;
    $saved = template_instance_test_save_server_keys();
    $_SERVER['SCRIPT_NAME'] = '/identification.php';
    unset($_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);
    $t = TemplateTestFactory::build();

    try {
        $t->setTheme($root, ThemeId::from('opt-out-theme'), 'template');
    } finally {
        template_instance_test_restore_server_keys($saved);
    }

    expect($t->getThemeconf('icon_dir'))
        ->toBe('not-swapped');
});

test('setTheme recurses into a distinct parent theme', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/child-theme', [
        'marker' => 'child',
        'parent' => 'parent-theme',
    ]);
    template_instance_test_write_themeconf($root . '/parent-theme', [
        'marker' => 'parent',
    ]);
    $t = TemplateTestFactory::build();

    $t->setTheme($root, ThemeId::from('child-theme'), 'template');

    $themes = template_instance_test_themes($t);
    expect($themes)
        ->toHaveCount(2)
        // The parent's own recursive setTheme() call appends its themes
        // entry before this (outer, child) call resumes and appends its
        // own -- so the parent lands at index 0, the child at index 1.
        ->and($themes[0]['id'])->toBe('parent-theme')
        ->and($themes[1]['id'])->toBe('child-theme');
});

test('setTheme does not recurse when a theme names itself as its own parent', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/self-parent-theme', [
        'marker' => 'self',
        'parent' => 'self-parent-theme',
    ]);
    $t = TemplateTestFactory::build();

    $t->setTheme($root, ThemeId::from('self-parent-theme'), 'template');

    expect($t->getTemplateVars('themes'))
        ->toHaveCount(1);
});

test('setTheme records both the theme id and the load_css flag on the appended themes entry', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/plain-theme', [
        'marker' => 'x',
    ]);
    $t = TemplateTestFactory::build();

    $t->setTheme($root, ThemeId::from('plain-theme'), 'template', false);

    $themes = template_instance_test_themes($t);
    expect($themes[0]['id'])->toBe('plain-theme')
        ->and($themes[0]['load_css'])->toBeFalse();
});

test('setTheme resolves local_head to a real file path when present and load_local_head is true', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    mkdir($root . '/lh-theme', 0o777, true);
    file_put_contents($root . '/lh-theme/local_head.tpl', 'x');
    template_instance_test_write_themeconf($root . '/lh-theme', [
        'marker' => 'x',
        'local_head' => 'local_head.tpl',
    ]);
    $t = TemplateTestFactory::build();

    $t->setTheme($root, ThemeId::from('lh-theme'), 'template', true, true);

    $themes = template_instance_test_themes($t);
    expect($themes[0]['local_head'])->toBe(realpath($root . '/lh-theme/local_head.tpl'));
});

test('setTheme treats a local_head value of "0" as absent, same as every other in_array sentinel', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/lh-zero-theme', [
        'marker' => 'x',
        'local_head' => '0',
    ]);
    $t = TemplateTestFactory::build();

    $t->setTheme($root, ThemeId::from('lh-zero-theme'), 'template', true, true);

    expect(template_instance_test_themes($t)[0])->not->toHaveKey('local_head');
});

test('setTheme treats an empty-string local_head as absent', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/lh-empty-theme', [
        'marker' => 'x',
        'local_head' => '',
    ]);
    $t = TemplateTestFactory::build();

    $t->setTheme($root, ThemeId::from('lh-empty-theme'), 'template', true, true);

    expect(template_instance_test_themes($t)[0])->not->toHaveKey('local_head');
});

test('setTheme defaults colorscheme to the given value when the theme does not already set one', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/cs-theme', [
        'marker' => 'x',
    ]);
    $t = TemplateTestFactory::build();

    $t->setTheme($root, ThemeId::from('cs-theme'), 'template', true, true, 'custom-scheme');

    expect($t->getThemeconf('colorscheme'))
        ->toBe('custom-scheme');
});

test('setTheme preserves an already-set colorscheme instead of overwriting it', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/cs-theme2', [
        'marker' => 'x',
        'colorscheme' => 'theme-defined',
    ]);
    $t = TemplateTestFactory::build();

    $t->setTheme($root, ThemeId::from('cs-theme2'), 'template', true, true, 'custom-scheme');

    expect($t->getThemeconf('colorscheme'))
        ->toBe('theme-defined');
});

test('setTheme merges themeconf directly into the flat "themeconf" template var, not nested under an index', function (): void {
    $root = rtrim(CurrentPathsTestFactory::get()->root, '/');
    template_instance_test_write_themeconf($root . '/merge-theme', [
        'marker' => 'flat-merge-check',
    ]);
    $t = TemplateTestFactory::build();

    $t->setTheme($root, ThemeId::from('merge-theme'), 'template');

    $tc = template_instance_test_assoc($t->getTemplateVars('themeconf'));
    expect($tc['icon_dir'] ?? null)->toBe('flat-merge-check')
        ->and($tc)
        ->not->toHaveKey(0);
});

// --- getTemplateDir / getThemeconf / themeConf ----------------------

test('getTemplateDir always returns the first appended dir, regardless of later setTemplateDir() calls', function (): void {
    $t = TemplateTestFactory::build('/first/dir');

    $t->setTemplateDir('/second/dir');

    expect($t->getTemplateDir())
        ->toBe('/first/dir');
});

test('getThemeconf returns an empty string when no themeconf var has been assigned', function (): void {
    $t = TemplateTestFactory::build();

    expect($t->getThemeconf('anything'))
        ->toBe('');
});

test('getThemeconf returns the raw (possibly non-string) value from an assigned themeconf array', function (): void {
    $t = TemplateTestFactory::build();
    $t->assignContext(new AdHocPageContext([
        'themeconf' => [
            'label' => 'Dark',
            'depth' => 3,
        ],
    ]));

    expect($t->getThemeconf('label'))
        ->toBe('Dark')
        ->and($t->getThemeconf('depth'))
        ->toBe(3)
        ->and($t->getThemeconf('missing'))
        ->toBe('');
});

test('themeConf narrows a non-string themeconf value down to an empty string', function (): void {
    $t = TemplateTestFactory::build();
    $t->assignContext(new AdHocPageContext([
        'themeconf' => [
            'label' => 'Dark',
            'depth' => 3,
        ],
    ]));

    expect($t->themeConf('label'))
        ->toBe('Dark')
        ->and($t->themeConf('depth'))
        ->toBe('');
});

// --- clearAssign -----------------------------------------------------

test('clearAssign removes a previously assigned template variable', function (): void {
    $t = TemplateTestFactory::build();
    $t->assignContext(new AdHocPageContext([
        'foo' => 'bar',
    ]));

    $t->clearAssign('foo');

    expect($t->getTemplateVars('foo'))
        ->toBeNull();
});

// --- p() ---------------------------------------------------------------

// --- parse -----------------------------------------------------------------

test('parse assigns ROOT_URL and ROOT_PATH before rendering', function (): void {
    $t = TemplateTestFactory::build();
    $tplDir = CurrentPathsTestFactory::get()->root . '/tpl/';
    mkdir($tplDir, 0o777, true);
    file_put_contents($tplDir . 'x.latte', 'x');
    $t->setTemplateDir($tplDir);

    $t->parse('x.latte');

    expect($t->getTemplateVars('ROOT_PATH'))
        ->toBe(CurrentPathsTestFactory::get()->root)
        ->and($t->getTemplateVars('ROOT_URL'))
        ->toBeString();
});

// --- concat --------------------------------------------------------------

test('concat appends to an existing string template variable and re-wraps as Html', function (): void {
    // Real regression, found live: a plain-string result rendered as
    // literal escaped HTML source text through any template declaring
    // its var `varType \Latte\Runtime\Html` with no explicit
    // `|noescape` filter (e.g. admin.latte's own $ADMIN_CONTENT) --
    // Latte only skips auto-escaping for an actual Html instance, never
    // for a `varType` annotation alone.
    $t = TemplateTestFactory::build();
    $t->concat('greeting', 'Hello ');
    $t->concat('greeting', 'World');

    $result = $t->getTemplateVars('greeting');
    expect($result)
        ->toBeInstanceOf(Html::class);
    assert($result instanceof Html);
    expect((string) $result)
        ->toBe('Hello World');
});

test('concat treats a non-string existing value as an empty prefix', function (): void {
    $t = TemplateTestFactory::build();
    $t->assignContext(new AdHocPageContext([
        'counter' => 42,
    ]));
    $t->concat('counter', 'suffix');

    $result = $t->getTemplateVars('counter');
    expect($result)
        ->toBeInstanceOf(Html::class);
    assert($result instanceof Html);
    expect((string) $result)
        ->toBe('suffix');
});

test('concat casts an existing Latte\Runtime\Html value to string instead of dropping it', function (): void {
    // Real regression: ADMIN_CONTENT is Html-wrapped once its own
    // producer (e.g. intro.latte) uses assignVarFromTemplate() --
    // CheckIntegrity.php's own concat('ADMIN_CONTENT', ...) call would
    // have silently discarded the whole existing value under the old
    // is_string()-only check, keeping only the newly concatenated
    // suffix.
    $t = TemplateTestFactory::build();
    $t->assignContext(new AdHocPageContext([
        'greeting' => new Html('Hello '),
    ]));
    $t->concat('greeting', 'World');

    $result = $t->getTemplateVars('greeting');
    expect($result)
        ->toBeInstanceOf(Html::class);
    assert($result instanceof Html);
    expect((string) $result)
        ->toBe('Hello World');
});

// --- picture/index buttons ------------------------------------------------

test('parsePictureButtons assigns registered buttons sorted by rank', function (): void {
    $t = TemplateTestFactory::build();
    $t->addPictureButton('<button-b>', 50);
    $t->addPictureButton('<button-a>', 10);

    $t->parsePictureButtons();

    expect($t->getTemplateVars('PLUGIN_PICTURE_BUTTONS'))
        ->toBe(['<button-a>', '<button-b>']);
});

test('parsePictureButtons does nothing when no button was ever registered', function (): void {
    $t = TemplateTestFactory::build();

    $t->parsePictureButtons();

    expect($t->getTemplateVars('PLUGIN_PICTURE_BUTTONS'))
        ->toBeNull();
});

test('parseIndexButtons assigns registered buttons sorted by rank', function (): void {
    $t = TemplateTestFactory::build();
    $t->addIndexButton('<index-b>', 99);
    $t->addIndexButton('<index-a>', 1);

    $t->parseIndexButtons();

    expect($t->getTemplateVars('PLUGIN_INDEX_BUTTONS'))
        ->toBe(['<index-a>', '<index-b>']);
});

// --- parse(): unresolvable filename -----------------------------------------

test('parse fatal-errors for an unresolvable filename', function (): void {
    $this->expectErrorLog();
    $t = TemplateTestFactory::build();

    set_error_handler(static fn (): bool => true);
    try {
        $t->parse('never-set-filename');
    } finally {
        restore_error_handler();
    }
})->throws(ResponseReadyException::class);

// --- combineScript / getCombinedScripts --------------------------------

/**
 * combineScript()/getCombinedScripts() log via
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

test('combineScript records a fatal error for an invalid load value', function (): void {
    $this->expectErrorLog();
    $t = TemplateTestFactory::build();
    templateInstanceTestErrorCollector()
        ->drain();

    $t->combineScript('x', load: 'bogus');

    $collected = templateInstanceTestErrorCollector()
        ->drain();
    expect($collected)
        ->toBe(["[ERROR] combineScript: invalid 'load' parameter"]);
});

test('combineScript maps load="footer" to loadMode 1', function (): void {
    $t = TemplateTestFactory::build();

    $t->combineScript('x', load: 'footer', path: 'x.js');

    expect($t->scriptLoader->getAll()['x']->loadMode)->toBe(1);
});

test('combineScript maps load="async" to loadMode 2', function (): void {
    $t = TemplateTestFactory::build();

    $t->combineScript('x', load: 'async', path: 'x.js');

    expect($t->scriptLoader->getAll()['x']->loadMode)->toBe(2);
});

test('combineScript defaults loadMode to 0 when load is not given', function (): void {
    $t = TemplateTestFactory::build();

    $t->combineScript('x', path: 'x.js');

    expect($t->scriptLoader->getAll()['x']->loadMode)->toBe(0);
});

test('combineScript explodes a comma-separated require string', function (): void {
    $t = TemplateTestFactory::build();

    $t->combineScript('x', require: 'a,b', path: 'x.js');

    expect($t->scriptLoader->getAll()['x']->precedents)->toBe(['a', 'b']);
});

test('combineScript treats a null require as no requirements', function (): void {
    $t = TemplateTestFactory::build();

    $t->combineScript('x', path: 'x.js');

    expect($t->scriptLoader->getAll()['x']->precedents)->toBe([]);
});

test('combineScript treats an empty-string require as no requirements', function (): void {
    $t = TemplateTestFactory::build();

    $t->combineScript('x', require: '', path: 'x.js');

    expect($t->scriptLoader->getAll()['x']->precedents)->toBe([]);
});

test('combineScript keeps a real string path', function (): void {
    $t = TemplateTestFactory::build();

    $t->combineScript('x', path: 'x.js');

    expect($t->scriptLoader->getAll()['x']->path)->toBe('x.js');
});

test('combineScript defaults version to "0" when not given', function (): void {
    $t = TemplateTestFactory::build();

    $t->combineScript('x', path: 'x.js');

    expect($t->scriptLoader->getAll()['x']->version)->toBe('0');
});

test('combineScript keeps a real string version', function (): void {
    $t = TemplateTestFactory::build();

    $t->combineScript('x', path: 'x.js', version: '3.2');

    expect($t->scriptLoader->getAll()['x']->version)->toBe('3.2');
});

test('combineScript keeps version=false as-is', function (): void {
    $t = TemplateTestFactory::build();

    $t->combineScript('x', path: 'x.js', version: false);

    expect($t->scriptLoader->getAll()['x']->version)->toBeFalse();
});

test('combineScript defaults is_template to false when not given', function (): void {
    $t = TemplateTestFactory::build();

    $t->combineScript('x', path: 'x.js');

    expect($t->scriptLoader->getAll()['x']->is_template)->toBeFalse();
});

test('combineScript sets is_template to true when given', function (): void {
    $t = TemplateTestFactory::build();

    $t->combineScript('x', path: 'x.js', template: true);

    expect($t->scriptLoader->getAll()['x']->is_template)->toBeTrue();
});

test('getCombinedScripts returns the combined-scripts placeholder for the header load', function (): void {
    $t = TemplateTestFactory::build();

    expect((string) $t->getCombinedScripts('header'))
        ->toBe(Template::COMBINED_SCRIPTS_TAG);
});

test('getCombinedScripts renders sync footer scripts as plain script tags', function (): void {
    // No explicit 'version', so it defaults to '0' (falsy), and
    // makeScriptSrc() falls back to AppInfo::VERSION -- see "combineScript
    // keeps version=false as-is" above for the version=false path, which
    // omits the "?v..." suffix entirely.
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/sync.js', 'console.log(1);');
    $t->combineScript('sync-script', load: 'footer', path: 'sync.js');

    $result = $t->getCombinedScripts('footer');

    expect((string) $result)
        ->toBe('<script type="text/javascript" src="sync.js?v' . AppInfo::VERSION . '"></script>');
});

test('getCombinedScripts renders async footer scripts via a dynamic script element', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/async.js', 'console.log(1);');
    $t->combineScript('async-script', load: 'async', path: 'async.js');

    $result = $t->getCombinedScripts('footer');

    $src = 'async.js?v' . AppInfo::VERSION;
    $expected = '<script type="text/javascript">' . "\n"
        . "(function() {\nvar s,after = document.getElementsByTagName('script')[document.getElementsByTagName('script').length-1];\n"
        . "s=document.createElement('script'); s.type='text/javascript'; s.async=true; s.src='{$src}';\n" . 'after = after.parentNode.insertBefore(s, after);' . "\n"
        . '})();' . "\n"
        . '</script>';
    expect((string) $result)
        ->toBe($expected);
});

test('getCombinedScripts prefixes the root URL onto the script src, in the correct order', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/sync.js', 'console.log(1);');
    $t->combineScript('sync-script', load: 'footer', path: 'sync.js');
    template_instance_test_root_path_override()
        ->push('http://example.test/root/');
    try {
        $result = $t->getCombinedScripts('footer');
    } finally {
        template_instance_test_root_path_override()->reset();
    }

    expect((string) $result)
        ->toBe('<script type="text/javascript" src="http://example.test/root/sync.js?v' . AppInfo::VERSION . '"></script>');
});

test('getCombinedScripts omits the version query string entirely for a combined script', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/a.js', 'console.log("a");');
    file_put_contents(CurrentPathsTestFactory::get()->root . '/b.js', 'console.log("b");');
    $t->combineScript('a', load: 'footer', path: 'a.js');
    $t->combineScript('b', load: 'footer', path: 'b.js');

    $result = $t->getCombinedScripts('footer');

    expect((string) $result)
        ->toContain('<script type="text/javascript" src="')
        ->and((string) $result)
        ->not->toContain('?v');
});

test('makeScriptSrc (via getCombinedScripts) uses a remote script\'s own path verbatim, with no root URL prefix or version suffix', function (): void {
    $t = TemplateTestFactory::build();
    $t->combineScript('remote-script', load: 'footer', path: 'https://cdn.example.com/foo.js');

    $result = $t->getCombinedScripts('footer');

    expect((string) $result)
        ->toBe('<script type="text/javascript" src="https://cdn.example.com/foo.js"></script>');
});

// --- footerScript --------------------------------------------------------

test('footerScript registers an inline script once its own required script is already known', function (): void {
    $t = TemplateTestFactory::build();
    $t->combineScript('foo', path: 'foo.js');

    $t->footerScript('console.log(1);', require: 'foo');

    expect($t->scriptLoader->inlineScripts)
        ->toBe(['console.log(1);']);
});

test('footerScript is a no-op for empty or whitespace-only content', function (): void {
    $t = TemplateTestFactory::build();

    $t->footerScript('');
    $t->footerScript("   \n");

    expect($t->scriptLoader->inlineScripts)
        ->toBe([]);
});

test('footerScript treats a null require as no requirements', function (): void {
    $t = TemplateTestFactory::build();

    $t->footerScript('console.log(1);');

    expect($t->scriptLoader->inlineScripts)
        ->toBe(['console.log(1);']);
});

test('footerScript explodes a comma-separated require string', function (): void {
    $t = TemplateTestFactory::build();
    $t->combineScript('a', path: 'a.js');
    $t->combineScript('b', path: 'b.js');

    $t->footerScript('console.log(1);', require: 'a,b');

    expect($t->scriptLoader->inlineScripts)
        ->toBe(['console.log(1);']);
});

test('footerScript actually reads the require param (fatal-errors for an unknown required script)', function (): void {
    $this->expectErrorLog();
    $t = TemplateTestFactory::build();

    set_error_handler(static fn (): bool => true);
    try {
        $t->footerScript('console.log(1);', require: 'totally-unknown-script-id');
    } finally {
        restore_error_handler();
    }
})->throws(ResponseReadyException::class);

// --- combineCss / getCombinedCss ----------------------------------------

test('combineCss derives id from md5(path) when id is not given', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');

    $t->combineCss('style.css');

    expect(template_instance_test_cssloader_registered($t->cssLoader))
        ->toHaveKey(md5('style.css'));
});

test('combineCss keeps a real string id when given', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');

    $t->combineCss('style.css', id: 'my-css');

    expect(template_instance_test_cssloader_registered($t->cssLoader))
        ->toHaveKey('my-css');
});

test('combineCss keeps version=false as-is', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');

    $t->combineCss('style.css', id: 'my-css', version: false);

    expect(template_instance_test_cssloader_registered($t->cssLoader)['my-css']->version)->toBeFalse();
});

test('combineCss forwards order and template through to CssLoader', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');

    $t->combineCss('style.css', id: 'my-css', order: 5, template: true);

    $registered = template_instance_test_cssloader_registered($t->cssLoader)['my-css'];
    // CssLoader::add() itself computes order*1000+counter -- confirming
    // combineCss() forwarded a real int order, not a string.
    expect($registered->order)
        ->toBe(5000)
        ->and($registered->is_template)
        ->toBeTrue();
});

test('getCombinedCss returns the combined-css placeholder', function (): void {
    $t = TemplateTestFactory::build();

    expect((string) $t->getCombinedCss())
        ->toBe(Template::COMBINED_CSS_TAG);
});

// --- finalizeHtml ---------------------------------------------------------

test('finalizeHtml appends a version query string for a truthy combined_css version', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');
    $t->combineCss('style.css', version: '7');

    $result = $t->finalizeHtml(Template::COMBINED_CSS_TAG);

    expect($result)
        ->toBe('<link rel="stylesheet" type="text/css" href="style.css?v7">');
});

test('finalizeHtml does not append a version query string when combined_css version is exactly false', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');
    $t->combineCss('style.css', version: false);

    $result = $t->finalizeHtml(Template::COMBINED_CSS_TAG);

    expect($result)
        ->toBe('<link rel="stylesheet" type="text/css" href="style.css">');
});

test('finalizeHtml builds the combined-css href by prefixing the root URL onto the combi path', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');
    template_instance_test_root_path_override()
        ->push('http://example.test/root/');
    try {
        $t->combineCss('style.css', version: false);

        $result = $t->finalizeHtml(Template::COMBINED_CSS_TAG);
    } finally {
        template_instance_test_root_path_override()->reset();
    }

    expect($result)
        ->toBe('<link rel="stylesheet" type="text/css" href="http://example.test/root/style.css">');
});

test('finalizeHtml clears the CSS loader so a second call does not re-emit already-flushed CSS', function (): void {
    $t = TemplateTestFactory::build();
    file_put_contents(CurrentPathsTestFactory::get()->root . '/style.css', 'body{}');
    $t->combineCss('style.css', version: false);
    $first = $t->finalizeHtml(Template::COMBINED_CSS_TAG);

    $second = $t->finalizeHtml(Template::COMBINED_CSS_TAG);

    expect($first)
        ->toContain('style.css')
        ->and($second)
        ->not->toContain('style.css');
});

test('finalizeHtml does not reprocess the combined-scripts tag once didHead is already true', function (): void {
    $t = TemplateTestFactory::build();
    $t->scriptLoader->getHeadScripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()));

    $result = $t->finalizeHtml(Template::COMBINED_SCRIPTS_TAG);

    expect($result)
        ->toBe(Template::COMBINED_SCRIPTS_TAG);
});

test('finalizeHtml injects head elements before </head> when the source contains that anchor', function (): void {
    $t = TemplateTestFactory::build();
    $t->htmlHead('<meta a>');

    $result = $t->finalizeHtml("<head>\n</head>\nbody");

    expect($result)
        ->toBe("<head>\n<meta a>\n</head>\nbody");
});

test('finalizeHtml does not touch </head> when no head elements were registered', function (): void {
    $t = TemplateTestFactory::build();

    $result = $t->finalizeHtml("<head>\n</head>\nbody");

    expect($result)
        ->toBe("<head>\n</head>\nbody");
});

test('finalizeHtml does not inject head elements when the source has no </head> anchor', function (): void {
    $t = TemplateTestFactory::build();
    $t->htmlHead('<meta a>');

    $result = $t->finalizeHtml('no head tag here');

    expect($result)
        ->toBe('no head tag here');
});

// --- htmlHead ---------------------------------------------------------

test('htmlHead is a no-op for empty or whitespace-only content', function (): void {
    $t = TemplateTestFactory::build();

    $t->htmlHead('');
    $t->htmlHead("   \n");

    expect($t->htmlHeadElements)
        ->toBe([]);
});

// --- localCssRules ----------------------------------------------------

test('localCssRules registers a combineCss entry for a real theme-specific rules file', function (): void {
    mkdir(CurrentPathsTestFactory::get()->root . '/local/css', 0o777, true);
    file_put_contents(CurrentPathsTestFactory::get()->root . '/local/css/mytheme-rules.css', 'body{}');
    $t = TemplateTestFactory::build();

    $t->localCssRules([
        [
            'id' => 'mytheme',
        ],
        [
            'id' => 'no-such-theme',
        ],
        [
            'no-id' => true,
        ],
    ]);

    expect(template_instance_test_cssloader_registered($t->cssLoader))
        ->toHaveKey(md5('local/css/mytheme-rules.css'));
});

test('localCssRules registers a combineCss entry for a real site-wide rules.css', function (): void {
    mkdir(CurrentPathsTestFactory::get()->root . '/local/css', 0o777, true);
    file_put_contents(CurrentPathsTestFactory::get()->root . '/local/css/rules.css', 'body{}');
    $t = TemplateTestFactory::build();

    $t->localCssRules([]);

    expect(template_instance_test_cssloader_registered($t->cssLoader))
        ->toHaveKey(md5('local/css/rules.css'));
});

test('localCssRules registers nothing when no local css files exist', function (): void {
    $t = TemplateTestFactory::build();

    $t->localCssRules([
        [
            'id' => 'mytheme',
        ],
    ]);

    expect(template_instance_test_cssloader_registered($t->cssLoader))
        ->toBe([]);
});

// --- loadThemeconf ----------------------------------------------------------

test('loadThemeconf returns an empty array for a theme directory that does not exist', function (): void {
    $t = TemplateTestFactory::build();

    expect($t->loadThemeconf(CurrentPathsTestFactory::get()->root . '/no-such-theme-dir'))->toBe([]);
});

test('loadThemeconf short-circuits on realpath() failure without ever attempting the theme.json read', function (): void {
    // Real gap: the sibling test above only asserts the RETURN value, which
    // stays [] whether the realpath()===false guard fires correctly OR is
    // broken entirely -- $themeconf starts as [] regardless. The REAL
    // observable difference is a genuine warning from attempting
    // file_get_contents($dir . '/theme.json') against a directory that was
    // never realpath()-resolved. Capture warnings directly to prove the
    // guard actually prevents that attempt.
    $t = TemplateTestFactory::build();

    $capturedWarnings = [];
    set_error_handler(function (int $errno, string $errstr) use (&$capturedWarnings): bool {
        $capturedWarnings[] = $errstr;

        return true;
    });

    try {
        $result = $t->loadThemeconf(CurrentPathsTestFactory::get()->root . '/no-such-theme-dir');
    } finally {
        restore_error_handler();
    }

    expect($result)
        ->toBe([])
        ->and($capturedWarnings)
        ->toBe([]);
});

test('loadThemeconf reads theme.json and maps its known fields onto the $themeconf shape', function (): void {
    $t = TemplateTestFactory::build();
    $dir = CurrentPathsTestFactory::get()->root . '/theme-real';
    mkdir($dir, 0o777, true);
    file_put_contents(
        $dir . '/theme.json',
        json_encode([
            'colorscheme' => 'Real Theme Scheme',
        ], JSON_THROW_ON_ERROR),
    );

    $result = $t->loadThemeconf($dir);

    // A bare AlwaysReturnNull mutation on the final `return $cached;`, or a
    // RemoveMethodCall dropping the ProcessCache::setStatic() a few lines above
    // it (which would leave the trailing ProcessCache::get() reading back
    // nothing), both collapse $result to something other than the real array.
    expect($result)
        ->toBe([
            'use_standard_pages' => false,
            'load_parent_css' => false,
            'colorscheme' => 'Real Theme Scheme',
        ]);
});

test('loadThemeconf assigns standard_pages\' own dynamic template vars, never a general themeconf-driven assign', function (): void {
    // Template::assign() is private, so no plugin/theme can push arbitrary
    // $theme_template_vars via assign() except this one hardcoded core
    // exception. Only a theme directory literally
    // named 'standard_pages' gets its own live CurrentConfig reads
    // assigned; every other theme.json read never touches assign() at all.
    // standardPagesSelectedSkin/Logo are private(set) on CurrentConfig (no
    // test seam to override them directly), so this asserts against their
    // real defaults rather than custom values -- galleryTitle IS a plain
    // mutable public property, exercised with a custom value for a
    // genuine round-trip proof.
    CurrentConfigTestFactory::get()->galleryTitle = 'sel-title';
    $t = TemplateTestFactory::build();
    $dir = CurrentPathsTestFactory::get()->root . '/standard_pages';
    mkdir($dir, 0o777, true);
    file_put_contents($dir . '/theme.json', json_encode([
        'colorscheme' => 'dark',
    ], JSON_THROW_ON_ERROR));

    $t->loadThemeconf($dir);

    expect($t->getTemplateVars('STD_PGS_SELECTED_SKIN'))
        ->toBe(CurrentConfigTestFactory::get()->standardPagesSelectedSkin)
        ->and($t->getTemplateVars('STD_PGS_SELECTED_LOGO'))
        ->toBe(CurrentConfigTestFactory::get()->standardPagesSelectedLogo)
        ->and($t->getTemplateVars('GALLERY_TITLE'))
        ->toBe('sel-title');
});

test('loadThemeconf caches per-directory: a second, different theme dir is not served the first dir\'s cached result', function (): void {
    // Kills a ConcatRemoveRight mutation on $cache_key ('themeconf:' . $dir
    // collapsing to the bare literal 'themeconf:'), which would make every
    // directory share one cache slot -- the second call below would then
    // wrongly return the first dir's already-cached themeconf.
    $t = TemplateTestFactory::build();
    $dirA = CurrentPathsTestFactory::get()->root . '/theme-a';
    $dirB = CurrentPathsTestFactory::get()->root . '/theme-b';
    mkdir($dirA, 0o777, true);
    mkdir($dirB, 0o777, true);
    file_put_contents($dirA . '/theme.json', json_encode([
        'colorscheme' => 'A',
    ], JSON_THROW_ON_ERROR));
    file_put_contents($dirB . '/theme.json', json_encode([
        'colorscheme' => 'B',
    ], JSON_THROW_ON_ERROR));

    $resultA = $t->loadThemeconf($dirA);
    $resultB = $t->loadThemeconf($dirB);

    expect($resultA['colorscheme'] ?? null)
        ->toBe('A')
        ->and($resultB['colorscheme'] ?? null)
        ->toBe('B');
});

test('loadThemeconf caches under the exact "themeconf:" . $dir key shape, not a bare or reversed variant', function (): void {
    // Poisons the two other plausible cache-key shapes a mutated concat
    // could produce -- ConcatRemoveLeft (bare $dir, dropping the
    // 'themeconf:' prefix) and ConcatSwitchSides ($dir . 'themeconf:',
    // operands reversed) -- with a recognizable sentinel value each. If
    // loadThemeconf() ever computed its cache key as either of those
    // variants, ProcessCache::has() would find one of these pre-seeded
    // entries and return its poisoned value instead of the real,
    // freshly-computed themeconf.
    $t = TemplateTestFactory::build();
    $dir = CurrentPathsTestFactory::get()->root . '/theme-format';
    mkdir($dir, 0o777, true);
    file_put_contents($dir . '/theme.json', json_encode([
        'colorscheme' => 'real-value',
    ], JSON_THROW_ON_ERROR));
    $realDir = (string) realpath($dir);
    $processCache = Kernel::container()->get(ProcessCache::class);
    if (! $processCache instanceof ProcessCache) {
        throw new LogicException('Container returned an unexpected type for ' . ProcessCache::class);
    }
    $processCache->set($realDir, [
        'poisoned' => 'bare-dir-key',
    ]);
    $processCache->set($realDir . 'themeconf:', [
        'poisoned' => 'switched-key',
    ]);

    $result = $t->loadThemeconf($dir);

    expect($result['colorscheme'] ?? null)
        ->toBe('real-value');
});
