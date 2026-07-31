<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseReadyException;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\PwgTemplateAdapter;
use Piwigo\Template\ScriptLoader;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;

/**
 * @return array<int|string, object>
 */
function template_instance_test_smarty_pre_filters(\Smarty\Smarty $smarty): array
{
    $property = new ReflectionProperty($smarty, 'BCPluginsAdapter');
    /** @var \Smarty\Extension\BCPluginsAdapter $adapter */
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
function template_instance_test_smarty_post_filters(\Smarty\Smarty $smarty): array
{
    $property = new ReflectionProperty($smarty, 'BCPluginsAdapter');
    /** @var \Smarty\Extension\BCPluginsAdapter $adapter */
    $adapter = $property->getValue($smarty);

    return $adapter->getPostFilters();
}

/**
 * @return array<string, \Piwigo\Template\Css>
 */
function template_instance_test_cssloader_registered(\Piwigo\Template\CssLoader $loader): array
{
    $property = new ReflectionProperty($loader, 'registered_css');

    /** @var array<string, \Piwigo\Template\Css> */
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

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-template-instance-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setDataLocation('data/');
    CurrentConfig::setDataDirChecked('1');
    CurrentUser::attachGlobals();
    ScriptLoader::setUrlService(new UrlService(new HtmlService()));
});

afterEach(function (): void {
    template_instance_test_rrmdir(CurrentPaths::get()->root);
    CurrentUser::reset();
    CurrentConfig::reset();
    CurrentPaths::reset();
    EventDispatcher::reset();
});

// --- constructor: Smarty engine base config -----------------------------

test('constructor disables Smarty html escaping', function (): void {
    $t = new Template();

    expect($t->smarty->escape_html)->toBeFalse();
});

test('constructor lowers error_reporting to exclude E_NOTICE when template debugging is off', function (): void {
    CurrentConfig::setDebugTemplate(false);

    $t = new Template();

    expect($t->smarty->error_reporting)->toBe(error_reporting() & ~E_NOTICE);
});

test('constructor leaves error_reporting untouched when template debugging is on', function (): void {
    CurrentConfig::setDebugTemplate(true);

    $t = new Template();

    expect($t->smarty->error_reporting)->toBeNull();
});

test('constructor casts compile_check to an int', function (): void {
    CurrentConfig::setTemplateCompileCheck(true);

    $t = new Template();

    expect($t->smarty->compile_check)->toBe(1);
});

// --- constructor: data dir not writable -------------------------------

test('constructor fatal-errors when the data directory cannot be made writable', function (): void {
    chmod(CurrentPaths::get()->root, 0o555);
    CurrentConfig::reset();
    CurrentConfig::setDataLocation('data/');

    set_error_handler(static fn (): bool => true);
    try {
        new Template();
    } finally {
        restore_error_handler();
        chmod(CurrentPaths::get()->root, 0o755);
    }
})->throws(ResponseReadyException::class);

test('constructor requests no backtrace when reporting the data-dir-not-writable error', function (): void {
    chmod(CurrentPaths::get()->root, 0o555);
    CurrentConfig::reset();
    CurrentConfig::setDataLocation('data/');

    $body = null;
    set_error_handler(static fn (): bool => true);
    try {
        new Template();
    } catch (ResponseReadyException $e) {
        $body = (string) $e->response()->getBody();
    } finally {
        restore_error_handler();
        chmod(CurrentPaths::get()->root, 0o755);
    }

    expect($body)->not->toBeNull()
        ->and($body)->not->toContain("#1\t");
});

test('constructor creates the configured data-location directory when data_dir_checked is unset', function (): void {
    CurrentConfig::setDataLocation('mydata/');
    CurrentConfig::setDataDirChecked(null);

    try {
        new Template();
    } catch (\LogicException) {
        // CurrentConfigService isn't initialised in this Unit test -- by
        // the time confUpdateParam() reaches it and throws, the
        // mkgetdir()/is_writable() check above (what this test verifies)
        // has already run.
    }

    expect(is_dir(CurrentPaths::get()->root . 'mydata'))->toBeTrue();
});

// --- constructor: compile dir / pwg assign / plugin registration -------

test('constructor creates and sets the templates_c compile dir', function (): void {
    $t = new Template();

    $expected = CurrentPaths::get()->root . 'data/templates_c';

    expect(is_dir($expected))->toBeTrue()
        ->and($t->smarty->getCompileDir())->toBe($expected . '/');
});

test('constructor assigns the pwg template adapter', function (): void {
    $t = new Template();

    expect($t->get_template_vars('pwg'))->toBeInstanceOf(PwgTemplateAdapter::class);
});

test('constructor registers exactly one Smarty pre-filter (prefilter_white_space)', function (): void {
    $t = new Template();

    expect(template_instance_test_smarty_pre_filters($t->smarty))->toHaveCount(1);
});

test('constructor registers every expected Smarty plugin', function (): void {
    $t = new Template();

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
    CurrentConfig::setCompiledTemplateCacheLanguage(true);

    $t = new Template();

    expect(template_instance_test_smarty_post_filters($t->smarty))->toHaveCount(1);
});

test('constructor does not register the language postfilter when cache-by-language is off', function (): void {
    CurrentConfig::setCompiledTemplateCacheLanguage(false);

    $t = new Template();

    expect(template_instance_test_smarty_post_filters($t->smarty))->toHaveCount(0);
});

test('constructor resets Smarty template dir to empty before adding its own (root=".")', function (): void {
    // Smarty's own default template_dir is ['./templates/'] (resolved
    // absolute internally) -- without the reset, addTemplateDir() below
    // would append onto that default instead of replacing it, and
    // get_template_dir() (index 0) would still read back the untouched
    // Smarty default (cwd + '/templates/') instead of just cwd + '/'.
    $t = new Template();

    expect($t->get_template_dir())->toBe(getcwd() . '/');
});

test('constructor derives jquery_code and plupload_code from the lang code when not already set', function (): void {
    Lang::setLangInfo(['code' => 'en-UK']);

    $t = new Template();

    $expected = ['code' => 'en-UK', 'jquery_code' => 'en-UK', 'plupload_code' => 'en_UK'];
    expect(Lang::langInfo())->toBe($expected)
        ->and($t->get_template_vars('lang_info'))->toBe($expected);
});

test('constructor registers template-extension extents when not in admin context, later duplicates winning', function (): void {
    mkdir(CurrentPaths::get()->root . '/template-extension/', 0o777, true);
    file_put_contents(CurrentPaths::get()->root . '/template-extension/first.tpl', 'a');
    file_put_contents(CurrentPaths::get()->root . '/template-extension/second.tpl', 'b');
    CurrentConfig::setExtentsForTemplates([
        'first.tpl' => ['dup-handle', 'N/A', 'N/A'],
        'second.tpl' => ['dup-handle', 'N/A', 'N/A'],
    ]);

    $t = new Template();

    expect($t->get_extent('orig.tpl', 'dup-handle'))->toBe(realpath(CurrentPaths::get()->root . '/template-extension/second.tpl'));
});

// --- set_template_dir ----------------------------------------------------

test('set_template_dir does not recompute compile_id once already set', function (): void {
    $t = new Template();
    $before = $t->smarty->compile_id;

    $t->set_template_dir(CurrentPaths::get()->root . '/some/other/dir');

    expect($t->smarty->compile_id)->toBe($before);
});

test('set_template_dir salts compile_id using the resolved realpath when the dir exists', function (): void {
    // Default construction calls set_template_dir($root) with $root='.'
    // (the constructor's own default), not CurrentPaths -- '.' resolves
    // relative to the test runner's cwd, which is a real, existing dir.
    $t = new Template();

    $expected = base_convert(hash('crc32b', '1' . realpath('.')), 16, 36);

    expect($t->smarty->compile_id)->toBe($expected);
});

test('set_template_dir salts compile_id with the raw dir string when realpath cannot resolve it', function (): void {
    $bogusDir = '/definitely/does/not/exist/' . bin2hex(random_bytes(4));
    $t = new Template($bogusDir);

    $expected = base_convert(hash('crc32b', '1' . $bogusDir), 16, 36);

    expect($t->smarty->compile_id)->toBe($expected);
});

// --- get_template_dir / get_themeconf / themeConf ----------------------

test('get_template_dir returns an empty string when Smarty has no template dir set', function (): void {
    $t = new Template();
    $t->smarty->setTemplateDir([]);

    expect($t->get_template_dir())->toBe('');
});

test('get_template_dir reads index 0 specifically, not any other index', function (): void {
    $t = new Template();
    $t->smarty->setTemplateDir([]);
    $t->smarty->addTemplateDir('/first/dir');
    $t->smarty->addTemplateDir('/second/dir');

    expect($t->get_template_dir())->toBe('/first/dir/');
});

test('get_themeconf returns an empty string when no themeconf var has been assigned', function (): void {
    $t = new Template();

    expect($t->get_themeconf('anything'))->toBe('');
});

test('get_themeconf returns the raw (possibly non-string) value from an assigned themeconf array', function (): void {
    $t = new Template();
    $t->smarty->assign('themeconf', ['label' => 'Dark', 'depth' => 3]);

    expect($t->get_themeconf('label'))->toBe('Dark')
        ->and($t->get_themeconf('depth'))->toBe(3)
        ->and($t->get_themeconf('missing'))->toBe('');
});

test('themeConf narrows a non-string themeconf value down to an empty string', function (): void {
    $t = new Template();
    $t->smarty->assign('themeconf', ['label' => 'Dark', 'depth' => 3]);

    expect($t->themeConf('label'))->toBe('Dark')
        ->and($t->themeConf('depth'))->toBe('');
});

// --- set_filename / set_extent / set_extents / get_extent --------------

test('set_filename delegates to set_filenames for a single handle', function (): void {
    $t = new Template();

    expect($t->set_filename('tail', 'footer.tpl'))->toBeTrue();
    expect($t->files['tail'])->toBe('footer.tpl');
});

test('set_filenames unsets an already-registered handle when its mapped filename is explicitly null', function (): void {
    $t = new Template();
    $t->set_filename('tail', 'footer.tpl');

    $result = $t->set_filenames(['tail' => null]);

    expect($result)->toBeTrue()
        ->and($t->files)->not->toHaveKey('tail');
});

test('set_extents returns false for a non-array argument', function (): void {
    $t = new Template();

    expect($t->set_extents('not-an-array'))->toBeFalse();
});

test('set_extents returns false when an array value has a non-string, non-int handle', function (): void {
    $t = new Template();

    expect($t->set_extents(['file.php' => [null, 'N/A', 'N/A']]))->toBeFalse();
});

test('set_extents returns false when a value is neither an array nor a string', function (): void {
    $t = new Template();

    expect($t->set_extents(['file.php' => 42]))->toBeFalse();
});

test('set_extent accepts the string-shorthand form and registers a real matching file', function (): void {
    $t = new Template();
    $extDir = CurrentPaths::get()->root . '/ext/';
    mkdir($extDir, 0o777, true);
    file_put_contents($extDir . 'myfile.tpl', 'hello');

    expect($t->set_extent('myfile.tpl', 'myhandle', $extDir))->toBeTrue();
    expect($t->get_extent('original.tpl', 'myhandle'))->toBe(realpath($extDir . 'myfile.tpl'));
});

test('set_extent overwrites an already-registered handle when overwrite is true (the default)', function (): void {
    $t = new Template();
    $extDir = CurrentPaths::get()->root . '/ext/';
    mkdir($extDir, 0o777, true);
    file_put_contents($extDir . 'first.tpl', 'a');
    file_put_contents($extDir . 'second.tpl', 'b');

    $t->set_extent('first.tpl', 'myhandle', $extDir);
    $t->set_extent('second.tpl', 'myhandle', $extDir, true);

    expect($t->get_extent('original.tpl', 'myhandle'))->toBe(realpath($extDir . 'second.tpl'));
});

test('set_extent registers a brand-new handle even when overwrite is false (nothing to protect yet)', function (): void {
    $t = new Template();
    $extDir = CurrentPaths::get()->root . '/ext/';
    mkdir($extDir, 0o777, true);
    file_put_contents($extDir . 'first.tpl', 'a');

    $t->set_extent('first.tpl', 'brand-new-handle', $extDir, false);

    expect($t->get_extent('orig.tpl', 'brand-new-handle'))->toBe(realpath($extDir . 'first.tpl'));
});

test('set_extents (array form) registers a handle when handle/param/theme all read from their correct array indices', function (): void {
    $t = new Template();
    $dir = CurrentPaths::get()->root . '/ext/';
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
    $t = new Template();
    $dir = CurrentPaths::get()->root . '/ext/';
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
    $t = new Template();
    $dir = CurrentPaths::get()->root . '/ext/';
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
    $t = new Template();
    $dir = CurrentPaths::get()->root . '/ext/';
    mkdir($dir, 0o777, true);
    file_put_contents($dir . 'file.tpl', 'x');

    $result = $t->set_extents(['file.tpl' => ['myhandle', 'N/A', 'exact-theme']], $dir, true, 'exact-theme');

    expect($result)->toBeTrue()
        ->and($t->get_extent('orig.tpl', 'myhandle'))->toBe(realpath($dir . 'file.tpl'));
});

test('set_extents does not register when theme matches neither the passed theme nor N/A', function (): void {
    $t = new Template();
    $dir = CurrentPaths::get()->root . '/ext/';
    mkdir($dir, 0o777, true);
    file_put_contents($dir . 'file.tpl', 'x');

    $t->set_extents(['file.tpl' => ['myhandle', 'N/A', 'some-other-theme']], $dir, true, 'different-theme');

    expect($t->get_extent('orig.tpl', 'myhandle'))->toBe('orig.tpl');
});

test('get_extent returns the given filename unchanged when no extent is registered for the handle', function (): void {
    $t = new Template();

    expect($t->get_extent('plain.tpl', 'unregistered'))->toBe('plain.tpl');
});

// --- assign_var_from_handle / clear_assign --------------------------------

test('assign_var_from_handle assigns the parsed handle output (returned, not echoed) and returns true', function (): void {
    $t = new Template();
    $tplDir = CurrentPaths::get()->root . '/tpl/';
    mkdir($tplDir, 0o777, true);
    file_put_contents($tplDir . 'partial.tpl', 'Hello {$name}');
    $t->set_template_dir($tplDir);
    $t->set_filename('partial', 'partial.tpl');
    $t->assign('name', 'World');

    $result = $t->assign_var_from_handle('greeting', 'partial');

    expect($result)->toBeTrue()
        ->and($t->get_template_vars('greeting'))->toBe('Hello World');
});

test('clear_assign removes a previously assigned template variable', function (): void {
    $t = new Template();
    $t->assign('foo', 'bar');

    $t->clear_assign('foo');

    expect($t->get_template_vars('foo'))->toBeNull();
});

// --- p() ---------------------------------------------------------------

test('p flushes the output buffer, then appends a working Smarty debug console when template debugging is on', function (): void {
    // Real bug, found live while adding this test: Smarty\Debug::display_debug()
    // (vendor/smarty/smarty/src/Debug.php) unconditionally calls
    // $obj->getSource() -- passing the bare $this->smarty engine (as this
    // method used to) always threw `Error: Call to undefined method
    // Smarty\Smarty::getSource()`, since only Smarty\Template implements
    // that method. See p()'s own updated call site for the minimal fix
    // (a throwaway 'string:' resource template instead of the bare engine).
    CurrentConfig::setDebugTemplate(true);
    $t = new Template();
    $t->output = 'body-output';

    ob_start();
    $t->p();
    $output = ob_get_clean();

    expect($output)->toStartWith('body-output')
        ->and($output)->toContain('Smarty Debug Console')
        ->and($t->get_template_vars('AAAA_DEBUG_TOTAL_TIME__'))->toBeString();
});

test('p does not attempt to build a debug console when template debugging is off', function (): void {
    CurrentConfig::setDebugTemplate(false);
    $t = new Template();
    $t->output = 'body-output';

    ob_start();
    $t->p();
    $output = ob_get_clean();

    expect($output)->toBe('body-output')
        ->and($t->get_template_vars('AAAA_DEBUG_TOTAL_TIME__'))->toBeNull();
});

// --- parse -----------------------------------------------------------------

test('parse assigns ROOT_URL and ROOT_PATH before compiling', function (): void {
    $t = new Template();
    $tplDir = CurrentPaths::get()->root . '/tpl/';
    mkdir($tplDir, 0o777, true);
    file_put_contents($tplDir . 'x.tpl', 'x');
    $t->set_template_dir($tplDir);
    $t->set_filename('x', 'x.tpl');

    $t->parse('x', true);

    expect($t->get_template_vars('ROOT_PATH'))->toBe(CurrentPaths::get()->root)
        ->and($t->get_template_vars('ROOT_URL'))->toBeString();
});

test('parse registers external filters before compiling (so they run) and unregisters them again afterward', function (): void {
    $t = new Template();
    $tplDir = CurrentPaths::get()->root . '/tpl/';
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
    CurrentConfig::setCompiledTemplateCacheLanguage(true);
    Lang::setLangInfo(['code' => 'fr_FR']);
    $t = new Template();
    $tplDir = CurrentPaths::get()->root . '/tpl/';
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
    CurrentConfig::setCompiledTemplateCacheLanguage(false);
    Lang::setLangInfo(['code' => 'fr_FR']);
    $t = new Template();
    $tplDir = CurrentPaths::get()->root . '/tpl/';
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
    $t = new Template();
    $t->concat('greeting', 'Hello ');
    $t->concat('greeting', 'World');

    expect($t->get_template_vars('greeting'))->toBe('Hello World');
});

test('concat treats a non-string existing value as an empty prefix', function (): void {
    $t = new Template();
    $t->assign('counter', 42);
    $t->concat('counter', 'suffix');

    expect($t->get_template_vars('counter'))->toBe('suffix');
});

// --- picture/index buttons ------------------------------------------------

test('parse_picture_buttons assigns registered buttons sorted by rank', function (): void {
    $t = new Template();
    $t->add_picture_button('<button-b>', 50);
    $t->add_picture_button('<button-a>', 10);

    $t->parse_picture_buttons();

    expect($t->get_template_vars('PLUGIN_PICTURE_BUTTONS'))->toBe(['<button-a>', '<button-b>']);
});

test('parse_picture_buttons does nothing when no button was ever registered', function (): void {
    $t = new Template();

    $t->parse_picture_buttons();

    expect($t->get_template_vars('PLUGIN_PICTURE_BUTTONS'))->toBeNull();
});

test('parse_index_buttons assigns registered buttons sorted by rank', function (): void {
    $t = new Template();
    $t->add_index_button('<index-b>', 99);
    $t->add_index_button('<index-a>', 1);

    $t->parse_index_buttons();

    expect($t->get_template_vars('PLUGIN_INDEX_BUTTONS'))->toBe(['<index-a>', '<index-b>']);
});

// --- prefilter/postfilter/outputfilter registration -----------------------

test('set_prefilter registers callbacks under their weight, kept sorted ascending', function (): void {
    $t = new Template();
    $t->set_prefilter('tail', 'strtoupper', 60);
    $t->set_prefilter('tail', 'strtolower', 10);

    expect(array_keys($t->external_filters['tail']))->toBe([10, 60]);
    expect($t->external_filters['tail'][10][0])->toBe(['pre', 'strtolower']);
});

test('set_postfilter registers a post-type filter entry', function (): void {
    $t = new Template();
    $t->set_postfilter('tail', 'strtoupper', 30);

    expect($t->external_filters['tail'][30][0])->toBe(['post', 'strtoupper']);
});

test('set_postfilter keeps registered weights sorted ascending', function (): void {
    $t = new Template();
    $t->set_postfilter('tail', 'strtoupper', 60);
    $t->set_postfilter('tail', 'strtolower', 10);

    expect(array_keys($t->external_filters['tail']))->toBe([10, 60]);
});

test('set_outputfilter registers an output-type filter entry', function (): void {
    $t = new Template();
    $t->set_outputfilter('tail', 'strtoupper', 40);

    expect($t->external_filters['tail'][40][0])->toBe(['output', 'strtoupper']);
});

test('set_outputfilter keeps registered weights sorted ascending', function (): void {
    $t = new Template();
    $t->set_outputfilter('tail', 'strtoupper', 60);
    $t->set_outputfilter('tail', 'strtolower', 10);

    expect(array_keys($t->external_filters['tail']))->toBe([10, 60]);
});

test('load_external_filters registers every filter with Smarty and salts the compile_id with its type+callback identity', function (): void {
    $t = new Template();
    $t->set_prefilter('tail', 'strtoupper');
    $before = $t->smarty->compile_id;

    $t->load_external_filters('tail');

    $expected = $before . '.' . base_convert(hash('crc32b', 'prestrtoupper'), 16, 36);
    expect($t->smarty->compile_id)->toBe($expected);
    $t->unload_external_filters('tail');
});

test('load_external_filters accumulates the type+callback identity across multiple registered filters, not just the last one', function (): void {
    $t = new Template();
    $t->set_prefilter('tail', 'strtoupper', 10);
    $t->set_prefilter('tail', 'strtolower', 20);
    $before = $t->smarty->compile_id;

    $t->load_external_filters('tail');

    $expected = $before . '.' . base_convert(hash('crc32b', 'prestrtoupperprestrtolower'), 16, 36);
    expect($t->smarty->compile_id)->toBe($expected);
    $t->unload_external_filters('tail');
});

test('load_external_filters derives the callback_key from the debug type when the callback is neither array nor string', function (): void {
    $t = new Template();
    $t->set_prefilter('tail', static fn (string $s): string => $s);
    $before = $t->smarty->compile_id;

    $t->load_external_filters('tail');

    $expected = $before . '.' . base_convert(hash('crc32b', 'preClosure'), 16, 36);
    expect($t->smarty->compile_id)->toBe($expected);
    $t->unload_external_filters('tail');
});

test('load_external_filters derives the callback_key from an [object, method] array callback, joining each element\'s own string (or debug-type fallback)', function (): void {
    $t = new Template();
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
    $t = new Template();
    $before = $t->smarty->compile_id;

    $t->load_external_filters('untouched-handle');

    expect($t->smarty->compile_id)->toBe($before);
});

test('unload_external_filters unregisters every filter across every registered weight, not just one', function (): void {
    $t = new Template();
    $t->set_prefilter('tail', 'template_instance_test_uppercase_prefilter', 10);
    $t->set_prefilter('tail', 'template_instance_test_lowercase_prefilter', 20);
    $t->load_external_filters('tail');
    expect(template_instance_test_smarty_pre_filters($t->smarty))->toHaveCount(3);

    $t->unload_external_filters('tail');

    expect(template_instance_test_smarty_pre_filters($t->smarty))->toHaveCount(1);
});

// --- parse(): missing handle ------------------------------------------------

test('parse fatal-errors for a handle with no registered filename', function (): void {
    $t = new Template();

    set_error_handler(static fn (): bool => true);
    try {
        $t->parse('never-set-filename');
    } finally {
        restore_error_handler();
    }
})->throws(ResponseReadyException::class);

// --- func_combine_script / func_get_combined_scripts -----------------------

test('func_combine_script trigger_errors when id is missing', function (): void {
    $t = new Template();

    $caught = null;
    set_error_handler(static function (int $errno, string $errstr) use (&$caught): bool {
        $caught = $errstr;
        return true;
    }, E_USER_ERROR);
    try {
        $t->func_combine_script([]);
    } finally {
        restore_error_handler();
    }

    expect($caught)->toBe("combine_script: missing 'id' parameter");
});

test('func_combine_script requires id to be a string even when the key is set', function (): void {
    $t = new Template();

    $caught = null;
    set_error_handler(static function (int $errno, string $errstr) use (&$caught): bool {
        $caught = $errstr;
        return true;
    }, E_USER_ERROR);
    try {
        $t->func_combine_script(['id' => 42, 'path' => 'x.js']);
    } finally {
        restore_error_handler();
    }

    expect($caught)->toBe("combine_script: missing 'id' parameter");
});

test('func_combine_script trigger_errors for an invalid load value', function (): void {
    $t = new Template();

    $caught = null;
    set_error_handler(static function (int $errno, string $errstr) use (&$caught): bool {
        $caught = $errstr;
        return true;
    }, E_USER_ERROR);
    try {
        $t->func_combine_script(['id' => 'x', 'load' => 'bogus']);
    } finally {
        restore_error_handler();
    }

    expect($caught)->toBe("combine_script: invalid 'load' parameter");
});

test('func_combine_script maps load="footer" to load_mode 1', function (): void {
    $t = new Template();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'load' => 'footer']);

    expect($t->scriptLoader->get_all()['x']->load_mode)->toBe(1);
});

test('func_combine_script maps load="async" to load_mode 2', function (): void {
    $t = new Template();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'load' => 'async']);

    expect($t->scriptLoader->get_all()['x']->load_mode)->toBe(2);
});

test('func_combine_script defaults load_mode to 0 when no load param is given', function (): void {
    $t = new Template();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js']);

    expect($t->scriptLoader->get_all()['x']->load_mode)->toBe(0);
});

test('func_combine_script explodes a real comma-separated require string', function (): void {
    $t = new Template();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'require' => 'a,b']);

    expect($t->scriptLoader->get_all()['x']->precedents)->toBe(['a', 'b']);
});

test('func_combine_script casts a non-string scalar require to a string before exploding', function (): void {
    $t = new Template();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'require' => 5]);

    expect($t->scriptLoader->get_all()['x']->precedents)->toBe(['5']);
});

test('func_combine_script treats a missing require key as no requirements', function (): void {
    $t = new Template();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js']);

    expect($t->scriptLoader->get_all()['x']->precedents)->toBe([]);
});

test('func_combine_script treats require=0 (int) as no requirements', function (): void {
    $t = new Template();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'require' => 0]);

    expect($t->scriptLoader->get_all()['x']->precedents)->toBe([]);
});

test('func_combine_script treats require="0" (string) as no requirements', function (): void {
    $t = new Template();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'require' => '0']);

    expect($t->scriptLoader->get_all()['x']->precedents)->toBe([]);
});

test('func_combine_script treats require="" (empty string) as no requirements', function (): void {
    $t = new Template();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'require' => '']);

    expect($t->scriptLoader->get_all()['x']->precedents)->toBe([]);
});

test('func_combine_script treats require=false as no requirements', function (): void {
    $t = new Template();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'require' => false]);

    expect($t->scriptLoader->get_all()['x']->precedents)->toBe([]);
});

test('func_combine_script treats a non-scalar require array as no requirements', function (): void {
    $t = new Template();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'require' => [1, 2, 3]]);

    expect($t->scriptLoader->get_all()['x']->precedents)->toBe([]);
});

test('func_combine_script discards a non-string path', function (): void {
    $t = new Template();

    $t->func_combine_script(['id' => 'x', 'path' => 42]);

    expect($t->scriptLoader->get_all()['x']->path)->toBeNull();
});

test('func_combine_script keeps a real string path', function (): void {
    $t = new Template();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js']);

    expect($t->scriptLoader->get_all()['x']->path)->toBe('x.js');
});

test('func_combine_script defaults version to "0" when missing', function (): void {
    $t = new Template();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js']);

    expect($t->scriptLoader->get_all()['x']->version)->toBe('0');
});

test('func_combine_script falls back to version "0" for a non-string version', function (): void {
    $t = new Template();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'version' => 7]);

    expect($t->scriptLoader->get_all()['x']->version)->toBe('0');
});

test('func_combine_script keeps a real string version', function (): void {
    $t = new Template();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'version' => '3.2']);

    expect($t->scriptLoader->get_all()['x']->version)->toBe('3.2');
});

test('func_combine_script defaults is_template to false when the template param is missing', function (): void {
    $t = new Template();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js']);

    expect($t->scriptLoader->get_all()['x']->is_template)->toBeFalse();
});

test('func_combine_script sets is_template to true when the template param is truthy', function (): void {
    $t = new Template();

    $t->func_combine_script(['id' => 'x', 'path' => 'x.js', 'template' => true]);

    expect($t->scriptLoader->get_all()['x']->is_template)->toBeTrue();
});

test('func_get_combined_scripts trigger_errors when load is missing', function (): void {
    $t = new Template();

    // Unrestricted (no 3rd-arg level mask): the suppressed E_USER_ERROR
    // trigger_error() call falls through to the very next line, which
    // reads the still-missing $params['load'] key directly (no isset())
    // -- a real "Undefined array key" E_WARNING this handler must also
    // absorb, confirmed live.
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

    expect($caught)->toContain("get_combined_scripts: missing 'load' parameter");
    // $params['load'] === 'header' is false for the missing/null case, so
    // it still falls through to the footer-scripts branch.
    expect($result)->toBe('');
});

test('func_get_combined_scripts returns the combined-scripts placeholder for the header load', function (): void {
    $t = new Template();

    expect($t->func_get_combined_scripts(['load' => 'header']))->toBe(Template::COMBINED_SCRIPTS_TAG);
});

test('func_get_combined_scripts renders sync footer scripts from get_footer_scripts()[0] as plain script tags', function (): void {
    // func_combine_script() never lets a script's version become the
    // literal false (unlike func_combine_css()) -- it always falls back to
    // a string, so make_script_src() always appends a "?v..." suffix here.
    // Exact match (not toContain) so positional mutations (dropping or
    // reordering the surrounding markup) are distinguishable too.
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/sync.js', 'console.log(1);');
    $t->func_combine_script(['id' => 'sync-script', 'path' => 'sync.js', 'load' => 'footer']);

    $result = $t->func_get_combined_scripts(['load' => 'footer']);

    expect($result)->toBe('<script type="text/javascript" src="sync.js?v' . AppInfo::VERSION . '"></script>');
});

test('func_get_combined_scripts renders async footer scripts from get_footer_scripts()[1] via a dynamic script element', function (): void {
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/async.js', 'console.log(1);');
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
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/sync.js', 'console.log(1);');
    $t->func_combine_script(['id' => 'sync-script', 'path' => 'sync.js', 'load' => 'footer']);
    \Piwigo\Url\RootPathOverride::push('http://example.test/root/');
    try {
        $result = $t->func_get_combined_scripts(['load' => 'footer']);
    } finally {
        \Piwigo\Url\RootPathOverride::reset();
    }

    expect($result)->toBe('<script type="text/javascript" src="http://example.test/root/sync.js?v' . AppInfo::VERSION . '"></script>');
});

test('func_get_combined_scripts omits the version query string entirely for a combined (version=false) script', function (): void {
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/a.js', 'console.log("a");');
    file_put_contents(CurrentPaths::get()->root . '/b.js', 'console.log("b");');
    $t->func_combine_script(['id' => 'a', 'path' => 'a.js', 'load' => 'footer']);
    $t->func_combine_script(['id' => 'b', 'path' => 'b.js', 'load' => 'footer']);

    $result = $t->func_get_combined_scripts(['load' => 'footer']);

    expect($result)->toContain('<script type="text/javascript" src="')
        ->and($result)->not->toContain('?v');
});

test('make_script_src (via func_get_combined_scripts) uses a remote script\'s own path verbatim, with no root URL prefix or version suffix', function (): void {
    $t = new Template();
    $t->func_combine_script(['id' => 'remote-script', 'path' => 'https://cdn.example.com/foo.js', 'load' => 'footer']);

    $result = $t->func_get_combined_scripts(['load' => 'footer']);

    expect($result)->toBe('<script type="text/javascript" src="https://cdn.example.com/foo.js"></script>');
});

test('make_script_src (via func_get_combined_scripts) throws when a combined_script event listener returns a non-string value', function (): void {
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/sync.js', 'console.log(1);');
    $t->func_combine_script(['id' => 'sync-script', 'path' => 'sync.js', 'load' => 'footer']);
    EventDispatcher::get()->addEventHandler('combined_script', static fn (): int => 42);

    try {
        $t->func_get_combined_scripts(['load' => 'footer']);
    } finally {
        EventDispatcher::reset();
    }
})->throws(Exception::class, "make_script_src(): a 'combined_script' event listener returned a non-string value");

// --- block_footer_script ----------------------------------------------------

test('block_footer_script registers an inline script once its own required script is already known', function (): void {
    $t = new Template();
    $t->func_combine_script(['id' => 'foo', 'path' => 'foo.js']);

    $t->block_footer_script(['require' => 'foo'], 'console.log(1);');

    expect($t->scriptLoader->inline_scripts)->toBe(['console.log(1);']);
});

test('block_footer_script does nothing on the opening-tag call (null content)', function (): void {
    $t = new Template();

    $t->block_footer_script([], null);

    expect($t->scriptLoader->inline_scripts)->toBe([]);
});

test('block_footer_script treats whitespace-only content as the opening-tag call (trims before checking emptiness)', function (): void {
    $t = new Template();

    $t->block_footer_script([], "   \n");

    expect($t->scriptLoader->inline_scripts)->toBe([]);
});

test('block_footer_script treats a missing require key as no requirements', function (): void {
    $t = new Template();

    $t->block_footer_script([], 'console.log(1);');

    expect($t->scriptLoader->inline_scripts)->toBe(['console.log(1);']);
});

test('block_footer_script treats every require sentinel value (0, "0", "", false, non-scalar array) as no requirements', function (): void {
    $t = new Template();

    foreach ([0, '0', '', false, [1, 2, 3]] as $sentinel) {
        $t->block_footer_script(['require' => $sentinel], 'console.log(1);');
    }

    expect($t->scriptLoader->inline_scripts)->toBe(array_fill(0, 5, 'console.log(1);'));
});

test('block_footer_script casts a non-string scalar require to a string before looking up the dependency', function (): void {
    $t = new Template();
    $t->func_combine_script(['id' => '5', 'path' => '5.js']);

    $t->block_footer_script(['require' => 5], 'console.log(1);');

    expect($t->scriptLoader->inline_scripts)->toBe(['console.log(1);']);
});

test('block_footer_script actually reads the require param (fatal-errors for an unknown required script)', function (): void {
    $t = new Template();

    set_error_handler(static fn (): bool => true);
    try {
        $t->block_footer_script(['require' => 'totally-unknown-script-id'], 'console.log(1);');
    } finally {
        restore_error_handler();
    }
})->throws(ResponseReadyException::class);

// --- func_combine_css / finalizeOutput (via fetchOutput) --------------------

test('func_combine_css fatal-errors when path is missing', function (): void {
    $t = new Template();

    set_error_handler(static fn (): bool => true);
    try {
        $t->func_combine_css([]);
    } finally {
        restore_error_handler();
    }
})->throws(ResponseReadyException::class);

test('func_combine_css fatal-errors for every path sentinel value (false, 0, "0", "", [])', function (): void {
    $t = new Template();

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
    $t = new Template();

    set_error_handler(static fn (): bool => true);
    try {
        $t->func_combine_css(['path' => 42]);
    } finally {
        restore_error_handler();
    }
})->throws(ResponseReadyException::class);

test('func_combine_css derives id from md5(path) when id is missing', function (): void {
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css']);

    expect(template_instance_test_cssloader_registered($t->cssLoader))->toHaveKey(md5('style.css'));
});

test('func_combine_css keeps a real string id when given', function (): void {
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css']);

    expect(template_instance_test_cssloader_registered($t->cssLoader))->toHaveKey('my-css');
});

test('func_combine_css falls back to md5(path) when id is a non-string value', function (): void {
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 42]);

    expect(template_instance_test_cssloader_registered($t->cssLoader))->toHaveKey(md5('style.css'));
});

test('func_combine_css defaults version to "0" when missing', function (): void {
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css']);

    expect(template_instance_test_cssloader_registered($t->cssLoader)['my-css']->version)->toBe('0');
});

test('func_combine_css keeps version=false as-is', function (): void {
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css', 'version' => false]);

    expect(template_instance_test_cssloader_registered($t->cssLoader)['my-css']->version)->toBeFalse();
});

test('func_combine_css falls back to version "0" for a non-string, non-false version', function (): void {
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css', 'version' => 7]);

    expect(template_instance_test_cssloader_registered($t->cssLoader)['my-css']->version)->toBe('0');
});

test('func_combine_css defaults order to 0 when missing', function (): void {
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css']);

    expect(template_instance_test_cssloader_registered($t->cssLoader)['my-css']->order)->toBe(0);
});

test('func_combine_css casts a real numeric order to an int', function (): void {
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/style.css', 'body{}');

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
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css', 'order' => '5.7']);

    expect(template_instance_test_cssloader_registered($t->cssLoader)['my-css']->order)->toBe(5000);
});

test('func_combine_css falls back to order 0 for a non-numeric order', function (): void {
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css', 'order' => 'not-numeric']);

    expect(template_instance_test_cssloader_registered($t->cssLoader)['my-css']->order)->toBe(0);
});

test('func_combine_css sets is_template to true when the template param is truthy', function (): void {
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css', 'template' => true]);

    expect(template_instance_test_cssloader_registered($t->cssLoader)['my-css']->is_template)->toBeTrue();
});

test('func_combine_css defaults is_template to false when the template param is missing', function (): void {
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/style.css', 'body{}');

    $t->func_combine_css(['path' => 'style.css', 'id' => 'my-css']);

    expect(template_instance_test_cssloader_registered($t->cssLoader)['my-css']->is_template)->toBeFalse();
});

test('finalizeOutput appends a version query string for a truthy combined_css version', function (): void {
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/style.css', 'body{}');
    $t->func_combine_css(['path' => 'style.css', 'version' => '7']);
    $t->output = Template::COMBINED_CSS_TAG;

    $result = $t->fetchOutput();

    expect($result)->toBe('<link rel="stylesheet" type="text/css" href="style.css?v7">');
});

test('finalizeOutput throws when a combined_css event listener returns a non-string value', function (): void {
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/style.css', 'body{}');
    $t->func_combine_css(['path' => 'style.css', 'version' => '7']);
    $t->output = Template::COMBINED_CSS_TAG;
    EventDispatcher::get()->addEventHandler('combined_css', static fn (): int => 42);

    $t->fetchOutput();
})->throws(Exception::class, "flush(): a 'combined_css' event listener returned a non-string value");

test('finalizeOutput does not append a version query string when combined_css version is exactly false', function (): void {
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/style.css', 'body{}');
    $t->func_combine_css(['path' => 'style.css', 'version' => false]);
    $t->output = Template::COMBINED_CSS_TAG;

    $result = $t->fetchOutput();

    expect($result)->toBe('<link rel="stylesheet" type="text/css" href="style.css">');
});

test('finalizeOutput builds the combined-css href by prefixing the root URL onto the combi path', function (): void {
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/style.css', 'body{}');
    \Piwigo\Url\RootPathOverride::push('http://example.test/root/');
    try {
        $t->func_combine_css(['path' => 'style.css', 'version' => false]);
        $t->output = Template::COMBINED_CSS_TAG;

        $result = $t->fetchOutput();
    } finally {
        \Piwigo\Url\RootPathOverride::reset();
    }

    expect($result)->toBe('<link rel="stylesheet" type="text/css" href="http://example.test/root/style.css">');
});

test('finalizeOutput clears the CSS loader so a second call does not re-emit already-flushed CSS', function (): void {
    $t = new Template();
    file_put_contents(CurrentPaths::get()->root . '/style.css', 'body{}');
    $t->func_combine_css(['path' => 'style.css', 'version' => false]);
    $t->output = Template::COMBINED_CSS_TAG;
    $first = $t->fetchOutput();

    $t->output = Template::COMBINED_CSS_TAG;
    $second = $t->fetchOutput();

    expect($first)->toContain('style.css')
        ->and($second)->not->toContain('style.css');
});

test('finalizeOutput does not reprocess the combined-scripts tag once did_head is already true', function (): void {
    $t = new Template();
    $t->scriptLoader->get_head_scripts();
    $t->output = Template::COMBINED_SCRIPTS_TAG;

    $result = $t->fetchOutput();

    expect($result)->toBe(Template::COMBINED_SCRIPTS_TAG);
});

test('finalizeOutput injects head elements before </head> when the source contains that anchor', function (): void {
    $t = new Template();
    $t->block_html_head([], null);
    $t->block_html_head([], '<meta a>');
    $t->output = "<head>\n</head>\nbody";

    $result = $t->fetchOutput();

    expect($result)->toBe("<head>\n<meta a>\n</head>\nbody");
});

test('finalizeOutput injects the accumulated html_style before </head> even with no head elements registered', function (): void {
    $t = new Template();
    $t->block_html_style([], null);
    $t->block_html_style([], 'body{color:red}');
    $t->output = "<head>\n</head>\nbody";

    $result = $t->fetchOutput();

    expect($result)->toBe("<head>\n<style type=\"text/css\">\nbody{color:red}</style>\n</head>\nbody");
});

test('finalizeOutput does not touch </head> when no head elements or html_style were registered', function (): void {
    $t = new Template();
    $t->output = "<head>\n</head>\nbody";

    $result = $t->fetchOutput();

    expect($result)->toBe("<head>\n</head>\nbody");
});

test('finalizeOutput does not inject head elements when the source has no </head> anchor', function (): void {
    $t = new Template();
    $t->block_html_head([], null);
    $t->block_html_head([], '<meta a>');
    $t->output = 'no head tag here';

    $result = $t->fetchOutput();

    expect($result)->toBe('no head tag here');
});

test('finalizeOutput resets html_style after injecting it, so a second call does not reapply it', function (): void {
    $t = new Template();
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
    $t = new Template();
    $t->output = 'hello';

    $t->fetchOutput();

    expect($t->output)->toBe('');
});

// --- block_html_head / block_html_style --------------------------------------

test('block_html_head trims whitespace-only content so it is treated as the opening-tag call', function (): void {
    $t = new Template();

    $t->block_html_head([], "   \n");

    expect($t->html_head_elements)->toBe([]);
});

test('block_html_style trims whitespace-only content so it is treated as the opening-tag call', function (): void {
    $t = new Template();
    $t->block_html_style([], "   \n");
    $t->output = "<head>\n</head>\nbody";

    $result = $t->fetchOutput();

    expect($result)->toBe("<head>\n</head>\nbody");
});

test('block_html_style accumulates multiple registrations rather than overwriting', function (): void {
    $t = new Template();
    $t->block_html_style([], 'a{color:red}');
    $t->block_html_style([], 'b{color:blue}');
    $t->output = "<head>\n</head>\nbody";

    $result = $t->fetchOutput();

    expect($result)->toBe("<head>\n<style type=\"text/css\">\na{color:red}\nb{color:blue}</style>\n</head>\nbody");
});

// --- prefilter_local_css ----------------------------------------------------

test('prefilter_local_css injects a combine_css tag for a real theme-specific rules file', function (): void {
    mkdir(CurrentPaths::get()->root . '/local/css', 0o777, true);
    file_put_contents(CurrentPaths::get()->root . '/local/css/mytheme-rules.css', 'body{}');
    $t = new Template();
    $t->smarty->assign('themes', [['id' => 'mytheme'], ['id' => 'no-such-theme'], 'not-an-array', ['no-id' => true]]);

    $result = Template::prefilter_local_css('before {get_combined_css} after', $t->smarty);

    expect($result)->toBe("before {combine_css path='local/css/mytheme-rules.css' order=10}\n{get_combined_css} after");
});

test('prefilter_local_css injects a combine_css tag for a real site-wide rules.css', function (): void {
    mkdir(CurrentPaths::get()->root . '/local/css', 0o777, true);
    file_put_contents(CurrentPaths::get()->root . '/local/css/rules.css', 'body{}');
    $t = new Template();

    $result = Template::prefilter_local_css('before {get_combined_css} after', $t->smarty);

    expect($result)->toBe("before {combine_css path='local/css/rules.css' order=10}\n{get_combined_css} after");
});

test('prefilter_local_css leaves the source untouched when no local css files exist', function (): void {
    $t = new Template();

    $result = Template::prefilter_local_css('before {get_combined_css} after', $t->smarty);

    expect($result)->toBe('before {get_combined_css} after');
});

test('prefilter_local_css continues past an invalid theme entry instead of stopping the whole loop', function (): void {
    mkdir(CurrentPaths::get()->root . '/local/css', 0o777, true);
    file_put_contents(CurrentPaths::get()->root . '/local/css/second-rules.css', 'body{}');
    $t = new Template();
    $t->smarty->assign('themes', ['not-an-array', ['id' => 'second']]);

    $result = Template::prefilter_local_css('before {get_combined_css} after', $t->smarty);

    expect($result)->toBe("before {combine_css path='local/css/second-rules.css' order=10}\n{get_combined_css} after");
});

// --- load_themeconf ----------------------------------------------------------

test('load_themeconf returns an empty array for a theme directory that does not exist', function (): void {
    $t = new Template();

    expect($t->load_themeconf(CurrentPaths::get()->root . '/no-such-theme-dir'))->toBe([]);
});
