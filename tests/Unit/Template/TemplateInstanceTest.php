<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Paths;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseReadyException;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\ScriptLoader;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;

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

// --- get_template_dir / get_themeconf / themeConf ----------------------

test('get_template_dir returns an empty string when Smarty has no template dir set', function (): void {
    $t = new Template();
    $t->smarty->setTemplateDir([]);

    expect($t->get_template_dir())->toBe('');
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

test('set_extent does not overwrite an already-registered handle when overwrite is false', function (): void {
    $t = new Template();
    $extDir = CurrentPaths::get()->root . '/ext/';
    mkdir($extDir, 0o777, true);
    file_put_contents($extDir . 'first.tpl', 'a');
    file_put_contents($extDir . 'second.tpl', 'b');

    $t->set_extent('first.tpl', 'myhandle', $extDir);
    $t->set_extent('second.tpl', 'myhandle', $extDir, false);

    expect($t->get_extent('original.tpl', 'myhandle'))->toBe(realpath($extDir . 'first.tpl'));
});

test('get_extent returns the given filename unchanged when no extent is registered for the handle', function (): void {
    $t = new Template();

    expect($t->get_extent('plain.tpl', 'unregistered'))->toBe('plain.tpl');
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

test('set_outputfilter registers an output-type filter entry', function (): void {
    $t = new Template();
    $t->set_outputfilter('tail', 'strtoupper', 40);

    expect($t->external_filters['tail'][40][0])->toBe(['output', 'strtoupper']);
});

test('load_external_filters registers every filter with Smarty and salts the compile_id', function (): void {
    $t = new Template();
    $t->set_prefilter('tail', 'strtoupper');
    $before = $t->smarty->compile_id;

    $t->load_external_filters('tail');

    expect($t->smarty->compile_id)->not->toBe($before);
    $t->unload_external_filters('tail');
});

test('load_external_filters is a no-op for a handle with no registered filters', function (): void {
    $t = new Template();
    $before = $t->smarty->compile_id;

    $t->load_external_filters('untouched-handle');

    expect($t->smarty->compile_id)->toBe($before);
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

// --- load_themeconf ----------------------------------------------------------

test('load_themeconf returns an empty array for a theme directory that does not exist', function (): void {
    $t = new Template();

    expect($t->load_themeconf(CurrentPaths::get()->root . '/no-such-theme-dir'))->toBe([]);
});
