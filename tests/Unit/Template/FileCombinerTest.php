<?php

declare(strict_types=1);

use Piwigo\Auth\AccessControl;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Event\Template\CombinedCssPostfilter;
use Piwigo\Html\HtmlService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\Combinable;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Event\CombinablePreparse;
use Piwigo\Template\FileCombiner;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

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
// real, minimally-configured Template (CurrentTemplate::current()->set()), not a full
// themed request.
//
// combine() now calls Piwigo\Auth\AccessControl::isAdmin() directly (P23
// batch 8d), which reads Piwigo\Users\CurrentUser (Legacy Coupling
// Retirement Track A batch A3) -- so no stub is needed beyond seeding a
// guest CurrentUser below, matching this test's old $GLOBALS['user'] stub.

// url_is_remote() -- always available now via composer autoload.files
// (src/Piwigo/Url/functions.php, P23 batch 8c), no explicit require needed.

beforeEach(function (): void {
    CurrentConfig::current()->setTemplateCompileCheck(false);
    CurrentConfig::current()->setTemplateCombineFiles(false);
    CurrentUser::current()->attachGlobals();
});

afterEach(function (): void {
    CurrentUser::current()->reset();
    \Piwigo\Config\CurrentConfig::current()->reset();
});

// --- computeForce() ---
//
// AccessControl::isAdmin() && (...) short-circuits for every guest-user
// test elsewhere in this file (beforeEach seeds a guest), so the
// admin-only branch's own inner boolean logic needs a real admin
// CurrentUser to ever execute at all -- invoked via reflection since
// computeForce() is private, same convention as process_combinable().

function invokeComputeForce(FileCombiner $combiner): bool
{
    $method = new ReflectionMethod($combiner, 'computeForce');

    /** @var bool */
    return $method->invoke($combiner);
}

function setAdminUser(): void
{
    CurrentUser::current()->set(new User(
        id: UserId::from(2),
        username: 'admin',
        email: '',
        language: 'en_UK',
        theme: 'modus',
        status: UserStatus::Admin,
        enabledHigh: false,
    ));
}

/**
 * FileCombiner only ever reads `isAdmin()` (via `computeForce()`), which
 * itself only reads CurrentUser::current() -- the HtmlRenderingInterface/
 * RedirectServiceInterface collaborators are never touched, so the shared
 * "throws if called" fakes from AccessControlTest.php are reused here
 * rather than duplicating them.
 */
function fileCombinerTestAccessControl(): AccessControl
{
    return new AccessControl(
        new \Piwigo\Tests\Unit\Auth\AccessControlTestFakeHtmlRendererDeniesAccess(),
        new \Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled(),
        CurrentUser::current(),
        \Piwigo\Config\CurrentConfig::current(),
    );
}

test('computeForce stays false for a guest even when the file type would otherwise qualify', function (): void {
    // Guards two things at once: the isAdmin() && (...) join being a real
    // AND (not an OR -- is_css=true alone would satisfy the right-hand
    // side, but a guest must never reach it) and the whole if() not being
    // negated (which would make a guest always enter it).
    $_SERVER['HTTP_CACHE_CONTROL'] = 'max-age=0';

    try {
        $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'css', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

        expect(invokeComputeForce($combiner))->toBeFalse();
    } finally {
        unset($_SERVER['HTTP_CACHE_CONTROL']);
    }
});

test('computeForce is true for an admin combining CSS with a cache-busting header, even though templateCompileCheck is on', function (): void {
    // is_css=true alone satisfies the (is_css || !templateCompileCheck())
    // side regardless of templateCompileCheck's own value -- proves that
    // clause is really an OR, not an AND.
    setAdminUser();
    CurrentConfig::current()->setTemplateCompileCheck(true);
    $_SERVER['HTTP_CACHE_CONTROL'] = 'max-age=0';

    try {
        $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'css', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

        expect(invokeComputeForce($combiner))->toBeTrue();
    } finally {
        unset($_SERVER['HTTP_CACHE_CONTROL']);
        CurrentUser::current()->reset();
        CurrentUser::current()->attachGlobals();
    }
});

test('computeForce is true for an admin combining JS with templateCompileCheck off and a cache-busting header', function (): void {
    // is_css=false here, so only !templateCompileCheck() can satisfy the
    // join -- proves the leading ! is real (RemoveNot would make this
    // false||false=false instead).
    setAdminUser();
    CurrentConfig::current()->setTemplateCompileCheck(false);
    $_SERVER['HTTP_CACHE_CONTROL'] = 'max-age=0';

    try {
        $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

        expect(invokeComputeForce($combiner))->toBeTrue();
    } finally {
        unset($_SERVER['HTTP_CACHE_CONTROL']);
        CurrentUser::current()->reset();
        CurrentUser::current()->attachGlobals();
    }
});

test('computeForce is false for an admin JS combine with no cache-busting header at all', function (): void {
    setAdminUser();
    CurrentConfig::current()->setTemplateCompileCheck(false);

    try {
        $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

        expect(invokeComputeForce($combiner))->toBeFalse();
    } finally {
        CurrentUser::current()->reset();
        CurrentUser::current()->attachGlobals();
    }
});

/**
 * Confirmed-equivalent this sweep, verified live via a temporary
 * sed-applied mutation plus a standalone script targeting the exact
 * scenario this class's own mutation-testing report suggested (an admin
 * combining with HTTP_PRAGMA's value starting with "no-cache" -- the
 * one case where strpos() returns the falsy-but-real int 0 instead of
 * false): computeForce()'s own RemoveBooleanCast on
 * `(bool) strpos($_SERVER['HTTP_PRAGMA'], 'no-cache')`. Despite the
 * classic PHP strpos()-returns-0-at-position-0 gotcha this cast looks
 * like it's guarding against, it's genuinely redundant here: this
 * expression is the last operand of an `&&` chain that itself is one
 * side of `||`, feeding straight into `return` -- and PHP's `&&`/`||`
 * operators *always* produce a real bool from their own truthiness
 * coercion of each operand (0 is falsy) regardless of an explicit cast
 * on any individual operand, same as this codebase's other
 * already-documented redundant-cast-in-boolean-context instances (see
 * clear_combined_files()'s own equivalence write-up further down this
 * file, and FilesystemHelperTest.php's mkgetdir() cluster). A position-0
 * 'no-cache' match at the very start of HTTP_PRAGMA returns false either
 * way -- confirmed identical with and without the cast.
 */

// --- initialKey() ---

/**
 * @return string[]
 */
function invokeInitialKey(FileCombiner $combiner): array
{
    $method = new ReflectionMethod($combiner, 'initialKey');

    /** @var string[] */
    return $method->invoke($combiner);
}

test('initialKey is empty for JS combining', function (): void {
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    expect(invokeInitialKey($combiner))->toBe([]);
});

test('initialKey seeds the scheme-less absolute root URL for CSS combining, so a scheme change alone busts the cache', function (): void {
    $urlService = new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride());
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'css', $urlService, Paths::fromRoot('/tmp/piwigo-file-combiner-test'), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    $key = invokeInitialKey($combiner);

    expect($key)->toHaveCount(1)
        ->and($key[0])->toBe($urlService->getAbsoluteRootUrl(false))
        ->and($key[0])->not->toStartWith('http://')
        ->and($key[0])->not->toStartWith('https://');
});

test('combine returns an empty array for no combinables', function (): void {
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    expect($combiner->combine())->toBe([]);
});

test('combine returns a single non-template combinable unchanged', function (): void {
    $combinable = new Combinable('my-script', 'themes/default/js/foo.js');
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), [$combinable]);

    $result = $combiner->combine();

    expect($result)->toHaveCount(1)
        ->and($result[0])->toBe($combinable);
});

test('combine passes remote combinables through without combining them', function (): void {
    $remote = new Combinable('remote-script', 'https://cdn.example.com/foo.js');
    $local = new Combinable('local-script', 'themes/default/js/bar.js');
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), [$remote, $local]);

    $result = $combiner->combine();

    expect($result)->toHaveCount(2)
        ->and($result[0])->toBe($remote)
        ->and($result[1])->toBe($local);
});

test('combine flushes pending items before appending a remote combinable, preserving declaration order', function (): void {
    // template_combine_files=true so the first item accumulates in
    // $pending instead of flushing itself immediately -- if the
    // is_remote branch's own flush_pending() call were skipped, $first
    // would only ever get flushed by combine()'s trailing call, landing
    // it AFTER $remote instead of before.
    CurrentConfig::current()->setTemplateCombineFiles(true);
    $first = new Combinable('first', 'themes/default/js/a.js');
    $remote = new Combinable('remote-script', 'https://cdn.example.com/foo.js');
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), [$first, $remote]);

    $result = $combiner->combine();

    expect($result)->toHaveCount(2)
        ->and($result[0])->toBe($first)
        ->and($result[1])->toBe($remote);
});

// --- flush_pending()'s multi-item merge path (real files, no Template
// dependency needed -- process_combinable()'s $return_content=true branch
// for a non-template combinable reads the file directly via
// file_get_contents()) ---

/**
 * Confirmed-equivalent this sweep, verified live via temporary
 * sed-applied mutations plus a standalone script exercising every
 * scalar shape combine()'s own multi-item cache key can realistically
 * carry (int 7/0/-3, bool true/false, float 1.5, and this class's own
 * documented default '0'): combine()'s own RemoveStringCast on
 * `$key[] = (string) $combinable->version;` and
 * `$key[] = (string) filemtime(...);`. $key's only consumer, in
 * flush_pending()'s count>1 branch, is `join('>', $key)` -- and PHP's
 * own array-to-string coercion inside join()/implode() applies the
 * *exact same* to-string conversion rules to a non-string array element
 * (bool/int/float/null, even a warned-about array) as an explicit
 * `(string)` cast would. Every value tried produced a byte-identical
 * combined filename with and without the cast (a mixed-type
 * $combinable->version is reachable at runtime since Combinable's own
 * $version property carries no PHP type declaration, only a
 * `string|false` docblock). For a single-item combinable this line's
 * mutation is doubly moot: flush_pending()'s count===1 branch discards
 * $key entirely without ever joining it.
 */
test('combine merges 2+ non-template files into a single combined output on disk, respecting declaration order', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-multi-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/a.js', "var a = 1;\n");
    file_put_contents($root . '/themes/default/js/b.js', "var b = 2;\n");
    CurrentConfig::current()->setTemplateCombineFiles(true);

    $first = new Combinable('a', 'themes/default/js/a.js');
    $second = new Combinable('b', 'themes/default/js/b.js');
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), [$first, $second]);

    try {
        $result = $combiner->combine();

        expect($result)->toHaveCount(1);
        $combinedPath = $result[0]->path;
        // Exact hash-derived filename, replicating flush_pending()'s own
        // join('>', $key) + crc32b + base_convert(..., 16, 36) formula --
        // pins the *1 and *36 base-conversion arguments precisely, not
        // just "a filename got produced".
        $expectedKey = 'themes/default/js/a.js>0>themes/default/js/b.js>0';
        $expectedFile = '_data/combined/' . base_convert(hash('crc32b', $expectedKey), 16, 36) . '.js';
        expect($combinedPath)->toBe($expectedFile);

        // The resulting Combinable disables its own version-based cache
        // busting (the combined file's hash already encodes freshness).
        expect($result[0]->version)->toBeFalse();

        expect(fileperms($root . '/' . $combinedPath) & 0o777)->toBe(0o644);

        // Exact combined output -- pins the "/*BEGIN header */\n" prefix,
        // the empty-header blank line, and every /*BEGIN path */ marker's
        // surrounding concatenation, not just that the substrings appear
        // somewhere.
        $content = file_get_contents($root . '/' . $combinedPath);
        expect($content)->toBe(
            "/*BEGIN header */\n\n/*BEGIN themes/default/js/a.js */\nvar a = 1;\n\n/*BEGIN themes/default/js/b.js */\nvar b = 2;\n\n"
        );
    } finally {
        file_combiner_test_rrmdir($root);
    }
});

test('combine does not rewrite an already-combined file on a second call (cache respected, not force-rebuilt)', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-multi-cache-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/a.js', "var a = 1;\n");
    file_put_contents($root . '/themes/default/js/b.js', "var b = 2;\n");
    CurrentConfig::current()->setTemplateCombineFiles(true);

    $combinerFirst = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), [
        new Combinable('a', 'themes/default/js/a.js'),
        new Combinable('b', 'themes/default/js/b.js'),
    ]);

    try {
        $firstResult = $combinerFirst->combine();
        $combinedPath = $root . '/' . $firstResult[0]->path;
        $originalMtime = filemtime($combinedPath);

        // Change a source file's content -- if the combined file were
        // rebuilt, its own content (and the hash-based filename) would
        // change; the cache-respecting path leaves both alone.
        sleep(1);
        file_put_contents($root . '/themes/default/js/a.js', "var a = 999;\n");

        $combinerSecond = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), [
            new Combinable('a', 'themes/default/js/a.js'),
            new Combinable('b', 'themes/default/js/b.js'),
        ]);
        $secondResult = $combinerSecond->combine();

        expect($secondResult[0]->path)->toBe($firstResult[0]->path)
            ->and(filemtime($combinedPath))->toBe($originalMtime)
            ->and(file_get_contents($combinedPath))->toContain('var a = 1;');
    } finally {
        file_combiner_test_rrmdir($root);
    }
});

test('combine invalidates the multi-item cache when a source file\'s mtime changes and templateCompileCheck is on', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-multi-mtime-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/a.js', "var a = 1;\n");
    file_put_contents($root . '/themes/default/js/b.js', "var b = 2;\n");
    CurrentConfig::current()->setTemplateCombineFiles(true);
    CurrentConfig::current()->setTemplateCompileCheck(true);

    $combinerFirst = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), [
        new Combinable('a', 'themes/default/js/a.js'),
        new Combinable('b', 'themes/default/js/b.js'),
    ]);

    try {
        $firstResult = $combinerFirst->combine();

        sleep(1);
        file_put_contents($root . '/themes/default/js/a.js', "var a = 999;\n");

        $combinerSecond = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), [
            new Combinable('a', 'themes/default/js/a.js'),
            new Combinable('b', 'themes/default/js/b.js'),
        ]);
        $secondResult = $combinerSecond->combine();

        // A real (string) filemtime() reading the *correct* absolute
        // path is what makes the hash key -- and therefore the combined
        // filename -- change here.
        expect($secondResult[0]->path)->not->toBe($firstResult[0]->path)
            ->and(file_get_contents($root . '/' . $secondResult[0]->path))->toContain('var a = 999;');
    } finally {
        file_combiner_test_rrmdir($root);
    }
});

test('combine merges 2+ CSS files into a single combined output, with a stripped @import promoted to the shared header', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-multi-css-header-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/css', 0o777, true);
    file_put_contents($root . '/themes/default/css/a.css', "@import \"../evil.css\";\nbody{color:red;}\n");
    file_put_contents($root . '/themes/default/css/b.css', "p{color:blue;}\n");
    CurrentConfig::current()->setTemplateCombineFiles(true);

    $urlService = new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride());
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'css', $urlService, Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), [
        new Combinable('a', 'themes/default/css/a.css'),
        new Combinable('b', 'themes/default/css/b.css'),
    ]);

    try {
        $result = $combiner->combine();

        expect($result)->toHaveCount(1);
        $combinedPath = $result[0]->path;
        $expectedKey = $urlService->getAbsoluteRootUrl(false) . '>themes/default/css/a.css>0>themes/default/css/b.css>0';
        expect($combinedPath)->toBe('_data/combined/' . base_convert(hash('crc32b', $expectedKey), 16, 36) . '.css');

        // Exact output: the suspicious @import is promoted to the shared
        // "/*BEGIN header */" section (not left inline, not dropped), and
        // both files' own content follows in declaration order.
        $content = file_get_contents($root . '/' . $combinedPath);
        expect($content)->toBe(
            "/*BEGIN header */\n@import \"../evil.css\";\n/*BEGIN themes/default/css/a.css */\n\nbody{color:red;}\n\n/*BEGIN themes/default/css/b.css */\np{color:blue;}\n\n"
        );
    } finally {
        file_combiner_test_rrmdir($root);
    }
});

test('add appends a single combinable', function (): void {
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);
    $combinable = new Combinable('my-script', 'themes/default/js/foo.js');

    $combiner->add($combinable);

    expect($combiner->combine())->toBe([$combinable]);
});

test('add merges an array of combinables', function (): void {
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);
    $first = new Combinable('first', 'themes/default/js/a.js');
    $second = new Combinable('second', 'themes/default/js/b.js');

    $combiner->add([$first, $second]);

    // template_combine_files is false, so each flushes as its own
    // single-item batch and is returned unchanged, in order.
    expect($combiner->combine())->toBe([$first, $second]);
});

/**
 * Confirmed-equivalent this sweep (both verified live via temporary
 * sed-applied mutations against this file's own existing suite): line
 * 46's own RemoveBooleanCast on `while ((bool) ($file = readdir($dir)))`
 * -- same redundant-cast-in-boolean-context pattern already documented
 * in FilesystemHelperTest.php's own mkgetdir() cluster (a `while`
 * condition already coerces its operand to bool the same way an
 * explicit cast would, so a file literally named "0" -- the one string
 * `(bool)` treats differently from a non-empty one -- is skipped
 * identically whether the cast is there or not); and line 51's own
 * RemoveFunctionCall on `closedir($dir);` -- $dir is a local variable
 * in this static method, never read again after the loop, so PHP's own
 * refcounted resource GC closes the handle once $dir goes out of scope
 * at function return regardless of the explicit call, confirmed live
 * via a real /proc/self/fd descriptor-count check across 10 repeated
 * clear_combined_files() calls with the closedir() call removed: the
 * count never grows past its first-call baseline. Same convention as
 * FilesystemHelperTest.php's/DerivativeCacheServiceTest.php's own
 * closedir()/clearstatcache() equivalence write-ups.
 */
test('clear_combined_files deletes only .js and .css files from the combined dir', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-clear-' . bin2hex(random_bytes(8));
    mkdir($root . '/_data/combined', 0o777, true);
    file_put_contents($root . '/_data/combined/a.js', 'x');
    file_put_contents($root . '/_data/combined/b.css', 'x');
    file_put_contents($root . '/_data/combined/c.txt', 'x');
    \Piwigo\Core\Kernel::boot(Paths::fromRoot($root));
    CurrentUser::current()->attachGlobals();
    CurrentConfig::current()->setDataLocation('_data/');

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
        \Piwigo\Core\Kernel::reset();
    }
});

test('clear_combined_files returns without error when the combined dir does not exist', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-noclear-' . bin2hex(random_bytes(8));
    \Piwigo\Core\Kernel::boot(Paths::fromRoot($root));
    CurrentUser::current()->attachGlobals();
    CurrentConfig::current()->setDataLocation('_data/');

    set_error_handler(static fn (): bool => true);
    try {
        FileCombiner::clear_combined_files();
        $ranToCompletion = true;
    } finally {
        restore_error_handler();
        \Piwigo\Core\Kernel::reset();
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

/**
 * Confirmed-equivalent this sweep, verified live via temporary
 * sed-applied mutations (each including a real reproduction script that
 * exercises the exact scenario, run against both the mutated and
 * original source to confirm byte-identical output):
 *
 * - flush_pending()'s own EmptyStringToNotEmpty on `$header = '';`
 *   (its count===1 branch, right before calling process_combinable()
 *   with $return_content=false): $header there is a fresh local
 *   variable passed only into *that one* by-ref call and never read
 *   again afterward in flush_pending() itself -- confirmed with a CSS
 *   template combinable carrying a suspicious @import (so process_css()
 *   really does append into $header via this exact call path), whose
 *   written single-item cache file's content is identical regardless of
 *   $header's starting value, since that file is built from $content
 *   alone (see process_combinable()'s own `file_put_contents(...,
 *   $content)`, never $header).
 * - process_combinable()'s own RemoveEarlyReturn on the is_template
 *   branch's trailing `return null;`: by the time this line is reached,
 *   the earlier `if ($return_content) { return $content; }` a few lines
 *   above has already NOT triggered, which only happens when
 *   $return_content is false -- and $return_content is never reassigned
 *   anywhere in this method. Falling through instead of returning here
 *   therefore always lands on `if ($return_content) {...}` (the
 *   non-template branch a few lines down) with that same
 *   already-proven-false value, skipping it identically, then reaching
 *   this method's own final `return null;` -- same return value, same
 *   side effects (the cache write already happened above), every time.
 */

test('combine reaches process_combinable for a single template combinable via flush_pending\'s own count===1 branch, updating its path to the cached file', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-single-template-flush-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/foo.js', "var a = 1;\n");
    Kernel::boot(Paths::fromRoot($root));
    CurrentUser::current()->attachGlobals();
    CurrentConfig::current()->setDataLocation('_data/');
    CurrentConfig::current()->setDataDirChecked('1');

    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    try {
        CurrentTemplate::current()->set(new Template());
        $combinable = new Combinable('foo-js', 'themes/default/js/foo.js');
        $combinable->is_template = true;
        $combiner->add($combinable);

        $result = $combiner->combine();

        // If flush_pending()'s own process_combinable() call for the
        // count===1 branch were skipped, $combinable would still point at
        // its original source path instead of the built cache file.
        expect($result)->toHaveCount(1)
            ->and($result[0]->path)->toStartWith('_data/combined/t')
            ->and($result[0]->path)->toEndWith('.js')
            ->and($result[0]->path)->not->toBe('themes/default/js/foo.js')
            ->and(file_exists($root . '/' . $result[0]->path))->toBeTrue();
    } finally {
        CurrentTemplate::current()->reset();
        file_combiner_test_rrmdir($root);
        Kernel::reset();
    }
});

test('process_combinable\'s single-file cache key is sensitive to the combinable\'s own path, not just its id', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-cache-key-path-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/foo.js', "var a = 1;\n");
    file_put_contents($root . '/themes/default/js/bar.js', "var a = 1;\n");
    Kernel::boot(Paths::fromRoot($root));
    CurrentUser::current()->attachGlobals();
    CurrentConfig::current()->setDataLocation('_data/');
    CurrentConfig::current()->setDataDirChecked('1');

    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    try {
        CurrentTemplate::current()->set(new Template());

        $a = new Combinable('same-id', 'themes/default/js/foo.js');
        $a->is_template = true;
        $header = '';
        invokeProcessCombinable($combiner, $a, false, false, $header);

        $b = new Combinable('same-id', 'themes/default/js/bar.js');
        $b->is_template = true;
        invokeProcessCombinable($combiner, $b, false, false, $header);

        expect($a->path)->not->toBe($b->path);
    } finally {
        CurrentTemplate::current()->reset();
        file_combiner_test_rrmdir($root);
        Kernel::reset();
    }
});

test('process_combinable\'s single-file cache key is sensitive to the combinable\'s own version', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-cache-key-version-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/foo.js', "var a = 1;\n");
    Kernel::boot(Paths::fromRoot($root));
    CurrentUser::current()->attachGlobals();
    CurrentConfig::current()->setDataLocation('_data/');
    CurrentConfig::current()->setDataDirChecked('1');

    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    try {
        CurrentTemplate::current()->set(new Template());

        $a = new Combinable('foo-js', 'themes/default/js/foo.js', '1.0');
        $a->is_template = true;
        $header = '';
        invokeProcessCombinable($combiner, $a, false, false, $header);

        $b = new Combinable('foo-js', 'themes/default/js/foo.js', '2.0');
        $b->is_template = true;
        invokeProcessCombinable($combiner, $b, false, false, $header);

        expect($a->path)->not->toBe($b->path);
    } finally {
        CurrentTemplate::current()->reset();
        file_combiner_test_rrmdir($root);
        Kernel::reset();
    }
});

test('process_combinable\'s single-file cache filename exactly matches the crc32b+base36 hash of path,version,mtime', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-cache-key-exact-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/foo.js', "var a = 1;\n");
    Kernel::boot(Paths::fromRoot($root));
    CurrentUser::current()->attachGlobals();
    CurrentConfig::current()->setDataLocation('_data/');
    CurrentConfig::current()->setDataDirChecked('1');
    // templateCompileCheck=true folds filemtime() into the key -- pins
    // that it's read from the combinable's own real absolute path, and
    // pins the *16 and *36 base-conversion arguments precisely.
    CurrentConfig::current()->setTemplateCompileCheck(true);

    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    try {
        CurrentTemplate::current()->set(new Template());
        $combinable = new Combinable('foo-js', 'themes/default/js/foo.js');
        $combinable->is_template = true;
        $header = '';

        invokeProcessCombinable($combiner, $combinable, false, false, $header);

        $mtime = filemtime($root . '/themes/default/js/foo.js');
        $expectedKey = implode(',', ['themes/default/js/foo.js', '0', $mtime]);
        $expectedFile = '_data/combined/t' . base_convert(hash('crc32b', $expectedKey), 16, 36) . '.js';
        expect($combinable->path)->toBe($expectedFile);
    } finally {
        CurrentTemplate::current()->reset();
        file_combiner_test_rrmdir($root);
        Kernel::reset();
    }
});

test('process_combinable reuses an already-combined template file (matching a filemtime-inclusive cache key) without ever touching CurrentTemplate', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-cache-hit-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/foo.js', "var a = 1;\n");
    Kernel::boot(Paths::fromRoot($root));
    CurrentUser::current()->attachGlobals();
    CurrentConfig::current()->setDataLocation('_data/');
    CurrentConfig::current()->setDataDirChecked('1');
    // templateCompileCheck=true makes the cache key include filemtime() --
    // exactly the "matching hash key including filemtime" case this test
    // is scoped to.
    CurrentConfig::current()->setTemplateCompileCheck(true);

    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    try {
        CurrentTemplate::current()->set(new Template());
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
        // through to the cache-miss branch, CurrentTemplate::current()->get() would
        // throw LogicException -- returning cleanly instead proves the
        // cache-hit short-circuit really returns before ever touching the
        // template machinery.
        CurrentTemplate::current()->reset();
        $combinable2 = new Combinable('foo-js', 'themes/default/js/foo.js');
        $combinable2->is_template = true;
        $header2 = '';
        $secondResult = invokeProcessCombinable($combiner, $combinable2, false, false, $header2);

        expect($secondResult)->toBeNull()
            ->and($combinable2->path)->toBe($cachedPath)
            ->and($combinable2->version)->toBeFalse();
    } finally {
        CurrentTemplate::current()->reset();
        file_combiner_test_rrmdir($root);
        Kernel::reset();
    }
});

test('process_combinable builds and writes a new combined JS file on a cache miss, dispatching to process_js', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-cache-miss-js-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/foo.js', "var a = 1;\n");
    Kernel::boot(Paths::fromRoot($root));
    CurrentUser::current()->attachGlobals();
    CurrentConfig::current()->setDataLocation('_data/');
    CurrentConfig::current()->setDataDirChecked('1');

    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    try {
        CurrentTemplate::current()->set(new Template());
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
        CurrentTemplate::current()->reset();
        file_combiner_test_rrmdir($root);
        Kernel::reset();
    }
});

test('process_combinable builds and writes a new combined CSS file on a cache miss, dispatching to process_css', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-cache-miss-css-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/css', 0o777, true);
    // Smarty's default delimiters are the same braces CSS rule blocks use
    // -- {literal} is the real-world way a CSS *template* (combine_css
    // template=true) must escape its own rule bodies.
    file_put_contents($root . '/themes/default/css/foo.css', "{literal}body{color:red;}{/literal}\n");
    Kernel::boot(Paths::fromRoot($root));
    CurrentUser::current()->attachGlobals();
    CurrentConfig::current()->setDataLocation('_data/');
    CurrentConfig::current()->setDataDirChecked('1');

    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'css', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    try {
        CurrentTemplate::current()->set(new Template());
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
        CurrentTemplate::current()->reset();
        file_combiner_test_rrmdir($root);
        Kernel::reset();
    }
});

test('process_combinable returns rendered content directly for a template combinable when return_content is true, instead of writing a single-item cache file', function (): void {
    // Every other is_template test in this file calls with
    // $returnContent=false (flush_pending()'s count===1 branch) -- this is
    // the OTHER real caller, its count>1 multi-item merge branch, which
    // always passes $return_content=true so each item's own rendered
    // content gets concatenated straight into the combined output instead
    // of being cached to its own separate file.
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-template-return-content-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/foo.js', "var a = {\$value};\n");
    Kernel::boot(Paths::fromRoot($root));
    CurrentUser::current()->attachGlobals();
    CurrentConfig::current()->setDataLocation('_data/');
    CurrentConfig::current()->setDataDirChecked('1');

    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    try {
        $template = new Template();
        $template->assign('value', 42);
        CurrentTemplate::current()->set($template);
        $combinable = new Combinable('foo-js', 'themes/default/js/foo.js');
        $combinable->is_template = true;
        $header = '';

        $result = invokeProcessCombinable($combiner, $combinable, true, false, $header);

        expect($result)->toBe("var a = 42;\n")
            // Unlike the $return_content=false branch, the combinable's own
            // path/version are left completely untouched -- no cache file
            // was ever written for it.
            ->and($combinable->path)->toBe('themes/default/js/foo.js')
            ->and(is_dir($root . '/_data/combined'))->toBeFalse();
    } finally {
        CurrentTemplate::current()->reset();
        file_combiner_test_rrmdir($root);
        Kernel::reset();
    }
});

test('process_combinable throws when a template combinable points at a file that does not exist', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-missing-real-path-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentUser::current()->attachGlobals();
    CurrentConfig::current()->setDataLocation('_data/');
    CurrentConfig::current()->setDataDirChecked('1');

    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    try {
        CurrentTemplate::current()->set(new Template());
        $combinable = new Combinable('missing-js', 'themes/default/js/does-not-exist.js');
        $combinable->is_template = true;
        $header = '';

        invokeProcessCombinable($combiner, $combinable, false, false, $header);
    } finally {
        CurrentTemplate::current()->reset();
        file_combiner_test_rrmdir($root);
        Kernel::reset();
    }
})->throws(Exception::class, 'process_combinable(): file not found for themes/default/js/does-not-exist.js');

test('process_css throws when a combined_css_postfilter listener returns something other than a CombinedCssPostfilter instance', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-postfilter-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/css', 0o777, true);
    file_put_contents($root . '/themes/default/css/foo.css', "body{color:red;}\n");
    EventDispatcher::get()->addEventHandler(CombinedCssPostfilter::class, static fn (): int => 42);

    $combinable = new Combinable('foo-css', 'themes/default/css/foo.css');
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'css', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), EventDispatcher::get(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    try {
        $header = '';
        invokeProcessCombinable($combiner, $combinable, true, false, $header);
    } finally {
        EventDispatcher::get()->reset();
        file_combiner_test_rrmdir($root);
    }
})->throws(\Error::class, 'must return an instance of');

/**
 * Confirmed-equivalent this sweep, verified live via temporary
 * sed-applied mutations against this file's own existing suite:
 *
 * - Both RemoveBooleanCast mutations on `if ((bool) preg_match_all(...))`
 *   (the url() pattern and the @import pattern): same redundant-cast-
 *   in-boolean-context reasoning as this file's other documented
 *   instances -- an `if` condition already coerces its operand to bool
 *   the same way an explicit cast would.
 * - Line 301's own DecrementInteger on `$match[1]` inside
 *   `str_contains($match[1], '://')` (the array-index literal, not an
 *   obvious numeric constant): $match[0] is always a superset string of
 *   $match[1] for this exact pattern (`@import` plus optional quote as
 *   fixed prefix, optional quote plus `;` as fixed suffix -- neither
 *   ever contains "://" on its own), so $match[0] contains "://" if and
 *   only if $match[1] does; str_contains() against either produces an
 *   identical result for every real @import target.
 *
 * Separately (not equivalence, a mutation-testing tool-coverage gap):
 * line 285's DecrementInteger on `$match[1][0]` (url() rewriting's
 * first-character check) and line 302's DecrementInteger on `$match[1]`
 * inside `is_readable($paths->root . $dir . '/' . $match[1])` are BOTH
 * already killed by this file's own pre-existing tests -- "process_css_rec
 * only rewrites url() references starting with '/'..." below, and
 * "process_css_rec resolves a doubly-nested @import..." further down,
 * respectively (confirmed live: each fails under its own sed-applied
 * mutation). pest --mutate's own report listed them as UNTESTED anyway;
 * same class of tool limitation as this project's other documented
 * pest-plugin-mutate blind spots (see MEMORY.md's
 * feedback_pest_mutate_blank_line_blind_spot /
 * feedback_pest_mutate_invisible_to_subprocess_tests).
 */
test('process_css_rec resolves a nested @import file recursively into the combined output', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-import-ok-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/css', 0o777, true);
    file_put_contents($root . '/themes/default/css/sub.css', "p { color: blue; }\n");
    file_put_contents($root . '/themes/default/css/main.css', "@import 'sub.css';\nbody{color:red;}\n");

    $combinable = new Combinable('main-css', 'themes/default/css/main.css');
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'css', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

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
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'css', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

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

test('process_css_rec still strips a "\.\." @import even when the path it traverses to is itself a real, readable file', function (): void {
    // The suspicious-path guard is 3 conditions OR'd together (traversal,
    // remote, unreadable) -- each must independently be able to trigger
    // the guard on its own. This is the ONLY way to isolate the '..'
    // check from the '! is_readable(...)' check: unlike the sibling test
    // above (whose '../evil.css' target is never created, so it would
    // *also* fail the is_readable() check on its own), 'sibling.css' here
    // genuinely exists and IS readable -- if the '..' check were ever
    // wrongly AND'd together with the remote check instead of being its
    // own independent OR'd clause, this '..'-but-readable-and-not-remote
    // case would slip through and get silently inlined instead of
    // rejected.
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-import-dotdot-readable-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/css/sub', 0o777, true);
    file_put_contents($root . '/themes/default/css/sub/main.css', "@import '../sibling.css';\n");
    file_put_contents($root . '/themes/default/css/sibling.css', "p{color:pink;}\n");

    $combinable = new Combinable('main-css', 'themes/default/css/sub/main.css');
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'css', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    try {
        $header = '';
        $result = invokeProcessCombinable($combiner, $combinable, true, false, $header);

        expect($result)->toBe("\n")
            ->and($header)->toBe("@import '../sibling.css';");
    } finally {
        file_combiner_test_rrmdir($root);
    }
});

test('process_css_rec throws when an @import target passes the is_readable() check but file_get_contents() still fails to read it', function (): void {
    // A Unix domain socket at the target path is the one realistic,
    // deterministic way to reach this: is_readable() is true for it (usual
    // permission bits), but file_get_contents() still fails to open it as
    // a regular file and returns false rather than throwing itself --
    // same technique as tests/Unit/Cache/PersistentFileCacheTest.php's own
    // established "unreadable despite existing" test. Distinct from the
    // sibling test above (missing/traversal/remote @import targets), which
    // never reach file_get_contents() at all -- this is the only path that
    // does, and gets false back instead of real content.
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-import-unreadable-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/css', 0o777, true);
    $socketPath = $root . '/themes/default/css/blocked.css';
    file_put_contents($root . '/themes/default/css/main.css', "@import 'blocked.css';\n");

    $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
    if (! $socket instanceof Socket) {
        throw new RuntimeException('socket_create failed');
    }
    expect(socket_bind($socket, $socketPath))->toBeTrue();

    $combinable = new Combinable('main-css', 'themes/default/css/main.css');
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'css', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    set_error_handler(static fn (): bool => true);
    try {
        $header = '';
        invokeProcessCombinable($combiner, $combinable, true, false, $header);
    } finally {
        restore_error_handler();
        socket_close($socket);
        file_combiner_test_rrmdir($root);
    }
})->throws(Exception::class, 'process_css_rec(): unable to read themes/default/css/blocked.css')
    ->skip(! extension_loaded('sockets'), 'requires ext-sockets to create a Unix domain socket file');

test('process_css_rec rewrites a relative url() reference into an embellished absolute URL', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-url-rewrite-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/css', 0o777, true);
    file_put_contents(
        $root . '/themes/default/css/main.css',
        "body{background: url('../img/bg.png');}\n"
    );

    $combinable = new Combinable('main-css', 'themes/default/css/main.css');
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'css', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    try {
        $header = '';
        $result = invokeProcessCombinable($combiner, $combinable, true, false, $header);

        // The literal relative reference is gone -- proves preg_match_all
        // really searched $css's own real content (not a hardcoded ''
        // fallback) and the match actually got rewritten.
        expect($result)->not->toContain("url('../img/bg.png')")
            ->and($result)->toContain('url(')
            // $search must be the FULL matched "url('...')" text, not just
            // the inner path -- otherwise str_replace() would only swap
            // the bare path substring, leaving a broken, doubly-nested
            // "url('url(...)')" behind.
            ->and($result)->not->toContain("url('url(")
            // getAbsoluteRootUrl(false), not (true) -- no scheme prefix.
            ->and($result)->not->toContain('http://')
            ->and($result)->not->toContain('https://');
    } finally {
        file_combiner_test_rrmdir($root);
    }
});

test('process_css_rec only rewrites url() references starting with "/" when checking the FIRST character of the path, not the last or second', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-url-slash-edge-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/css', 0o777, true);
    file_put_contents(
        $root . '/themes/default/css/main.css',
        "a{background: url('/absolute/path.png');}\n"
        . "b{background: url('a/b.png');}\n"
        . "c{background: url('rel/path/');}\n"
    );

    $combinable = new Combinable('main-css', 'themes/default/css/main.css');
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'css', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    try {
        $header = '';
        $result = invokeProcessCombinable($combiner, $combinable, true, false, $header);

        // Absolute path (starts with '/') is left completely untouched.
        expect($result)->toContain("url('/absolute/path.png')")
            // 'a/b.png' doesn't start with '/' (even though its SECOND
            // character is '/') -- must still be rewritten.
            ->and($result)->not->toContain("url('a/b.png')")
            // 'rel/path/' doesn't start with '/' (even though its LAST
            // character is '/') -- must still be rewritten.
            ->and($result)->not->toContain("url('rel/path/')");
    } finally {
        file_combiner_test_rrmdir($root);
    }
});

test('process_css_rec resolves a relative url() reference against the CSS file\'s own subdirectory, not the combined root', function (): void {
    // main.css lives in a subdirectory -- $relative must be dir+match,
    // not just one side or the other, or a swapped concatenation.
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-url-subdir-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/css/sub', 0o777, true);
    file_put_contents($root . '/themes/default/css/sub/main.css', "body{background: url(\"../img/bg.png\");}\n");

    $combinable = new Combinable('main-css', 'themes/default/css/sub/main.css');
    $urlService = new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride());
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'css', $urlService, Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    try {
        $header = '';
        $result = invokeProcessCombinable($combiner, $combinable, true, false, $header);

        $expectedRelative = 'themes/default/css/sub' . '/../img/bg.png';
        $expectedEmbellished = $urlService->embellishUrl($urlService->getAbsoluteRootUrl(false) . $expectedRelative);
        expect($result)->toBe("body{background: url({$expectedEmbellished});}\n");
    } finally {
        file_combiner_test_rrmdir($root);
    }
});

test('process_css_rec leaves a remote or data-URI url() reference untouched', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-url-skip-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/css', 0o777, true);
    file_put_contents(
        $root . '/themes/default/css/main.css',
        "a{background: url('https://cdn.example.com/x.png');}\nb{background: url('data:image/png;base64,AAA');}\n"
    );

    $combinable = new Combinable('main-css', 'themes/default/css/main.css');
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'css', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    try {
        $header = '';
        $result = invokeProcessCombinable($combiner, $combinable, true, false, $header);

        expect($result)->toContain("url('https://cdn.example.com/x.png')")
            ->and($result)->toContain("url('data:image/png;base64,AAA')");
    } finally {
        file_combiner_test_rrmdir($root);
    }
});

test('process_css_rec resolves a doubly-nested @import against the correct subdirectory at each level', function (): void {
    // Distinguishes the recursive call's own $dir argument from a
    // corrupted computation -- a wrong dir here would make the deepest
    // import's is_readable() check miss, stripping it into $header
    // instead of inlining "DEEPEST" below.
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-nested-dir-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/css/subdir', 0o777, true);
    file_put_contents($root . '/themes/default/css/main.css', "@import 'subdir/sub.css';\n");
    file_put_contents($root . '/themes/default/css/subdir/sub.css', "@import 'nested.css';\n");
    file_put_contents($root . '/themes/default/css/subdir/nested.css', "p{color:green;}\n");

    $combinable = new Combinable('main-css', 'themes/default/css/main.css');
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'css', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    try {
        $header = '';
        $result = invokeProcessCombinable($combiner, $combinable, true, false, $header);

        // Each nesting level compounds an extra trailing newline from its
        // own source file's line ending after the @import statement --
        // "p{color:green;}\n" from nested.css itself, plus one from
        // sub.css's own line, plus one from main.css's own line.
        expect($result)->toBe("p{color:green;}\n\n\n")
            ->and($header)->toBe('');
    } finally {
        file_combiner_test_rrmdir($root);
    }
});

test('process_combinable throws for a non-template combinable whose file cannot be read', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-nontemplate-missing-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);

    $combinable = new Combinable('missing', 'themes/default/js/does-not-exist.js');
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    set_error_handler(static fn (): bool => true);
    try {
        $header = '';
        invokeProcessCombinable($combiner, $combinable, true, false, $header);
    } finally {
        restore_error_handler();
        file_combiner_test_rrmdir($root);
    }
})->throws(Exception::class, 'do_combine(): unable to read themes/default/js/does-not-exist.js');

test('process_combinable dispatches a non-template combinable to process_js when read successfully', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-nontemplate-js-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/foo.js', "  var a = 1;  ;;\n");

    $combinable = new Combinable('foo', 'themes/default/js/foo.js');
    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    try {
        $header = '';
        $result = invokeProcessCombinable($combiner, $combinable, true, false, $header);

        expect($result)->toBe("var a = 1;\n");
    } finally {
        file_combiner_test_rrmdir($root);
    }
});

test('process_combinable registers the template file under a handle combining the loader type and combinable id', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-handle-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/foo.js', "var a = 1;\n");
    Kernel::boot(Paths::fromRoot($root));
    CurrentUser::current()->attachGlobals();
    CurrentConfig::current()->setDataLocation('_data/');
    CurrentConfig::current()->setDataDirChecked('1');

    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);
    $template = new Template();

    try {
        CurrentTemplate::current()->set($template);
        $combinable = new Combinable('my-handle-id', 'themes/default/js/foo.js');
        $combinable->is_template = true;
        $header = '';

        invokeProcessCombinable($combiner, $combinable, false, false, $header);

        expect($template->files)->toHaveKey('js.my-handle-id');
    } finally {
        CurrentTemplate::current()->reset();
        file_combiner_test_rrmdir($root);
        Kernel::reset();
    }
});

test('process_combinable notifies combinable_preparse listeners before parsing a template combinable', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-preparse-notify-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/foo.js', "var a = 1;\n");
    Kernel::boot(Paths::fromRoot($root));
    CurrentUser::current()->attachGlobals();
    CurrentConfig::current()->setDataLocation('_data/');
    CurrentConfig::current()->setDataDirChecked('1');

    $notifiedWith = null;
    EventDispatcher::get()->addTypedHandler(CombinablePreparse::class, function (CombinablePreparse $event) use (&$notifiedWith): void {
        $notifiedWith = $event;
    });

    $combiner = new FileCombiner(fileCombinerTestAccessControl(), 'js', new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), Paths::fromRoot($root), EventDispatcher::get(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), []);

    try {
        CurrentTemplate::current()->set(new Template());
        $combinable = new Combinable('foo-js', 'themes/default/js/foo.js');
        $combinable->is_template = true;
        $header = '';

        invokeProcessCombinable($combiner, $combinable, false, false, $header);

        // $notifiedWith is captured by-ref inside the closure above --
        // PHPStan can't narrow it past that boundary from the expect()
        // assertion alone (a by-ref/closure narrowing loss, same class as
        // this codebase's other instances of the same limitation).
        assert($notifiedWith instanceof CombinablePreparse);
        expect($notifiedWith)->not->toBeNull()
            ->and($notifiedWith->combinable)->toBe($combinable);
    } finally {
        EventDispatcher::get()->reset();
        CurrentTemplate::current()->reset();
        file_combiner_test_rrmdir($root);
        Kernel::reset();
    }
});

test('process_js trims whitespace/semicolons even from a null content argument', function (): void {
    $method = new ReflectionMethod(FileCombiner::class, 'process_js');

    expect($method->invoke(null, null))->toBe(";\n")
        ->and($method->invoke(null, "  var a=1;  \n"))->toBe("var a=1;\n");
});
