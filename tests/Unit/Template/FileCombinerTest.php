<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Paths;
use Piwigo\Html\HtmlService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\Combinable;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\FileCombiner;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;

// combine()'s multi-item merge path (mkgetdir()+file_put_contents() under
// PHPWG_ROOT_PATH) -- the full cascade through legacy free functions/
// is_admin() etc. -- is Smarty-rendering/file-I/O integration, already
// covered indirectly by the Browser suite (see docs/PLAN.md's P16 audit
// note) -- not re-tested here. What's under test at this level: combine()'s
// behavior for 0-1 non-template items and remote items, which never touch
// the filesystem (confirmed by reading flush_pending()/process_combinable()'s
// own branches).
//
// process_combinable()'s own is_template branch (cache-hit short-circuit,
// cache-miss build+write, the real-path-not-found guard, and CSS/JS
// dispatch) is invoked directly via reflection further down, bypassing
// combine()'s legacy-bootstrap-coupled entry points -- it only needs a
// real, minimally-configured Template (CurrentTemplate::set()), not a full
// themed request.
//
// combine() now calls Piwigo\Auth\AccessControl::isAdmin() directly (P23
// batch 8d), which reads Piwigo\Users\CurrentUser (Legacy Coupling
// Retirement Track A batch A3) -- so no stub is needed beyond seeding a
// guest CurrentUser below, matching this test's old $GLOBALS['user'] stub.

// url_is_remote() -- always available now via composer autoload.files
// (src/Piwigo/Url/functions.php, P23 batch 8c), no explicit require needed.

beforeEach(function (): void {
    CurrentConfig::setTemplateCompileCheck(false);
    CurrentConfig::setTemplateCombineFiles(false);
    CurrentUser::attachGlobals();
});

afterEach(function (): void {
    CurrentUser::reset();
    CurrentConfig::reset();
});

test('combine returns an empty array for no combinables', function (): void {
    $combiner = new FileCombiner('js', new UrlService(new HtmlService()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), []);

    expect($combiner->combine())->toBe([]);
});

test('combine returns a single non-template combinable unchanged', function (): void {
    $combinable = new Combinable('my-script', 'themes/default/js/foo.js');
    $combiner = new FileCombiner('js', new UrlService(new HtmlService()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), [$combinable]);

    $result = $combiner->combine();

    expect($result)->toHaveCount(1)
        ->and($result[0])->toBe($combinable);
});

test('combine passes remote combinables through without combining them', function (): void {
    $remote = new Combinable('remote-script', 'https://cdn.example.com/foo.js');
    $local = new Combinable('local-script', 'themes/default/js/bar.js');
    $combiner = new FileCombiner('js', new UrlService(new HtmlService()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), [$remote, $local]);

    $result = $combiner->combine();

    expect($result)->toHaveCount(2)
        ->and($result[0])->toBe($remote)
        ->and($result[1])->toBe($local);
});

test('add appends a single combinable', function (): void {
    $combiner = new FileCombiner('js', new UrlService(new HtmlService()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), []);
    $combinable = new Combinable('my-script', 'themes/default/js/foo.js');

    $combiner->add($combinable);

    expect($combiner->combine())->toBe([$combinable]);
});

test('add merges an array of combinables', function (): void {
    $combiner = new FileCombiner('js', new UrlService(new HtmlService()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), []);
    $first = new Combinable('first', 'themes/default/js/a.js');
    $second = new Combinable('second', 'themes/default/js/b.js');

    $combiner->add([$first, $second]);

    // template_combine_files is false, so each flushes as its own
    // single-item batch and is returned unchanged, in order.
    expect($combiner->combine())->toBe([$first, $second]);
});

test('clear_combined_files deletes only .js and .css files from the combined dir', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-clear-' . bin2hex(random_bytes(8));
    mkdir($root . '/_data/combined', 0o777, true);
    file_put_contents($root . '/_data/combined/a.js', 'x');
    file_put_contents($root . '/_data/combined/b.css', 'x');
    file_put_contents($root . '/_data/combined/c.txt', 'x');
    \Piwigo\Core\CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setDataLocation('_data/');

    try {
        FileCombiner::clear_combined_files();

        expect(file_exists($root . '/_data/combined/a.js'))->toBeFalse();
        expect(file_exists($root . '/_data/combined/b.css'))->toBeFalse();
        expect(file_exists($root . '/_data/combined/c.txt'))->toBeTrue();
    } finally {
        unlink($root . '/_data/combined/c.txt');
        rmdir($root . '/_data/combined');
        rmdir($root . '/_data');
        rmdir($root);
        \Piwigo\Core\CurrentPaths::reset();
    }
});

test('clear_combined_files returns without error when the combined dir does not exist', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-noclear-' . bin2hex(random_bytes(8));
    \Piwigo\Core\CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setDataLocation('_data/');

    set_error_handler(static fn (): bool => true);
    try {
        FileCombiner::clear_combined_files();
        $ranToCompletion = true;
    } finally {
        restore_error_handler();
        \Piwigo\Core\CurrentPaths::reset();
    }

    expect($ranToCompletion)->toBeTrue();
});

// --- process_combinable()'s is_template branch (cache-hit/cache-miss,
// real-path-not-found, CSS/JS dispatch) + process_css()/process_css_rec() ---
//
// Real filesystem, real (but minimally themed) Template, invoked via
// reflection since process_combinable() is private -- same "bypass the
// legacy-bootstrap-coupled public entry point to reach the private logic
// directly" convention as ScriptLoaderTest.php's own
// compute_script_topological_order() helper.

function file_combiner_test_rrmdir(string $dir): void
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
        is_dir($path) ? file_combiner_test_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function invokeProcessCombinable(FileCombiner $combiner, Combinable $combinable, bool $returnContent, bool $force, string &$header): ?string
{
    $method = new ReflectionMethod($combiner, 'process_combinable');
    /** @var string|null */
    return $method->invokeArgs($combiner, [$combinable, $returnContent, $force, &$header]);
}

test('process_combinable reuses an already-combined template file (matching a filemtime-inclusive cache key) without ever touching CurrentTemplate', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-cache-hit-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/foo.js', "var a = 1;\n");
    CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setDataLocation('_data/');
    CurrentConfig::setDataDirChecked('1');
    // templateCompileCheck=true makes the cache key include filemtime() --
    // exactly the "matching hash key including filemtime" case this test
    // is scoped to.
    CurrentConfig::setTemplateCompileCheck(true);

    $combiner = new FileCombiner('js', new UrlService(new HtmlService()), Paths::fromRoot($root), []);

    try {
        CurrentTemplate::set(new Template());
        $combinable = new Combinable('foo-js', 'themes/default/js/foo.js');
        $combinable->is_template = true;
        $header = '';
        // First call: cache miss -- builds and writes the combined file.
        $firstResult = invokeProcessCombinable($combiner, $combinable, false, false, $header);
        $cachedPath = $combinable->path;

        expect($firstResult)->toBeNull()
            ->and(file_exists($root . '/' . $cachedPath))->toBeTrue();

        // Second call: a *fresh* Combinable with the same original
        // path/version (so it recomputes the identical cache key), with
        // CurrentTemplate deliberately torn down first. If this fell
        // through to the cache-miss branch, CurrentTemplate::get() would
        // throw LogicException -- returning cleanly instead proves the
        // cache-hit short-circuit really returns before ever touching the
        // template machinery.
        CurrentTemplate::reset();
        $combinable2 = new Combinable('foo-js', 'themes/default/js/foo.js');
        $combinable2->is_template = true;
        $header2 = '';
        $secondResult = invokeProcessCombinable($combiner, $combinable2, false, false, $header2);

        expect($secondResult)->toBeNull()
            ->and($combinable2->path)->toBe($cachedPath)
            ->and($combinable2->version)->toBeFalse();
    } finally {
        CurrentTemplate::reset();
        file_combiner_test_rrmdir($root);
        CurrentPaths::reset();
    }
});

test('process_combinable builds and writes a new combined JS file on a cache miss, dispatching to process_js', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-cache-miss-js-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/foo.js', "var a = 1;\n");
    CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setDataLocation('_data/');
    CurrentConfig::setDataDirChecked('1');

    $combiner = new FileCombiner('js', new UrlService(new HtmlService()), Paths::fromRoot($root), []);

    try {
        CurrentTemplate::set(new Template());
        $combinable = new Combinable('foo-js', 'themes/default/js/foo.js');
        $combinable->is_template = true;
        $originalVersion = $combinable->version;
        $header = '';

        $result = invokeProcessCombinable($combiner, $combinable, false, false, $header);

        expect($result)->toBeNull()
            ->and($combinable->path)->toStartWith('_data/combined/t')
            ->and($combinable->path)->toEndWith('.js')
            // The cache-miss branch never touches version -- only the
            // cache-hit short-circuit does (see the test above).
            ->and($combinable->version)->toBe($originalVersion)
            ->and(file_exists($root . '/' . $combinable->path))->toBeTrue()
            // process_js() trims trailing whitespace/semicolons and
            // re-appends ";\n" -- confirms dispatch went through
            // process_js(), not process_css().
            ->and(file_get_contents($root . '/' . $combinable->path))->toBe("var a = 1;\n");
    } finally {
        CurrentTemplate::reset();
        file_combiner_test_rrmdir($root);
        CurrentPaths::reset();
    }
});

test('process_combinable builds and writes a new combined CSS file on a cache miss, dispatching to process_css', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-cache-miss-css-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/css', 0o777, true);
    // Smarty's default delimiters are the same braces CSS rule blocks use
    // -- {literal} is the real-world way a CSS *template* (combine_css
    // template=true) must escape its own rule bodies.
    file_put_contents($root . '/themes/default/css/foo.css', "{literal}body{color:red;}{/literal}\n");
    CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setDataLocation('_data/');
    CurrentConfig::setDataDirChecked('1');

    $combiner = new FileCombiner('css', new UrlService(new HtmlService()), Paths::fromRoot($root), []);

    try {
        CurrentTemplate::set(new Template());
        $combinable = new Combinable('foo-css', 'themes/default/css/foo.css');
        $combinable->is_template = true;
        $header = '';

        $result = invokeProcessCombinable($combiner, $combinable, false, false, $header);

        expect($result)->toBeNull()
            ->and($combinable->path)->toStartWith('_data/combined/t')
            ->and($combinable->path)->toEndWith('.css')
            ->and(file_exists($root . '/' . $combinable->path))->toBeTrue()
            // The {literal} markers are gone and no minification/trimming
            // happened -- process_css() (not process_js()) ran.
            ->and(file_get_contents($root . '/' . $combinable->path))->toBe("body{color:red;}\n");
    } finally {
        CurrentTemplate::reset();
        file_combiner_test_rrmdir($root);
        CurrentPaths::reset();
    }
});

test('process_combinable throws when a template combinable points at a file that does not exist', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-missing-real-path-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setDataLocation('_data/');
    CurrentConfig::setDataDirChecked('1');

    $combiner = new FileCombiner('js', new UrlService(new HtmlService()), Paths::fromRoot($root), []);

    try {
        CurrentTemplate::set(new Template());
        $combinable = new Combinable('missing-js', 'themes/default/js/does-not-exist.js');
        $combinable->is_template = true;
        $header = '';

        invokeProcessCombinable($combiner, $combinable, false, false, $header);
    } finally {
        CurrentTemplate::reset();
        file_combiner_test_rrmdir($root);
        CurrentPaths::reset();
    }
})->throws(Exception::class, 'process_combinable(): file not found for themes/default/js/does-not-exist.js');

test('process_css throws when a combined_css_postfilter listener returns a non-string value', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-postfilter-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/css', 0o777, true);
    file_put_contents($root . '/themes/default/css/foo.css', "body{color:red;}\n");
    EventDispatcher::get()->addEventHandler('combined_css_postfilter', static fn (): int => 42);

    $combinable = new Combinable('foo-css', 'themes/default/css/foo.css');
    $combiner = new FileCombiner('css', new UrlService(new HtmlService()), Paths::fromRoot($root), []);

    try {
        $header = '';
        invokeProcessCombinable($combiner, $combinable, true, false, $header);
    } finally {
        EventDispatcher::reset();
        file_combiner_test_rrmdir($root);
    }
})->throws(Exception::class, "process_css(): a 'combined_css_postfilter' event listener returned a non-string value");

test('process_css_rec resolves a nested @import file recursively into the combined output', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-import-ok-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/css', 0o777, true);
    file_put_contents($root . '/themes/default/css/sub.css', "p { color: blue; }\n");
    file_put_contents($root . '/themes/default/css/main.css', "@import 'sub.css';\nbody{color:red;}\n");

    $combinable = new Combinable('main-css', 'themes/default/css/main.css');
    $combiner = new FileCombiner('css', new UrlService(new HtmlService()), Paths::fromRoot($root), []);

    try {
        $header = '';
        $result = invokeProcessCombinable($combiner, $combinable, true, false, $header);

        expect($result)->toBe("p { color: blue; }\n\nbody{color:red;}\n")
            ->and($header)->toBe('');
    } finally {
        file_combiner_test_rrmdir($root);
    }
});

test('process_css_rec strips path-traversal, remote, and unreadable @import directives into the header instead of inlining them', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-import-suspicious-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/css', 0o777, true);
    file_put_contents(
        $root . '/themes/default/css/main.css',
        "@import 'missing.css';\n@import '../evil.css';\n@import 'https://cdn.example.com/x.css';\nbody{color:red;}\n"
    );

    $combinable = new Combinable('main-css', 'themes/default/css/main.css');
    $combiner = new FileCombiner('css', new UrlService(new HtmlService()), Paths::fromRoot($root), []);

    try {
        $header = '';
        $result = invokeProcessCombinable($combiner, $combinable, true, false, $header);

        // Each of the three unsafe cases (genuinely missing file, '..'
        // traversal, remote '://' URL) hits the same guard and is removed
        // from the output, with its raw @import directive preserved in
        // $header instead (@import must stay first in the final combined
        // file -- see FileCombiner::process_css_rec()'s own docblock).
        expect($result)->toBe("\n\n\nbody{color:red;}\n")
            ->and($header)->toBe("@import 'missing.css';@import '../evil.css';@import 'https://cdn.example.com/x.css';");
    } finally {
        file_combiner_test_rrmdir($root);
    }
});
