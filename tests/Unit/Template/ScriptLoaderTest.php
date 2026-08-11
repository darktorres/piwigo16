<?php

declare(strict_types=1);

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Http\ResponseReadyException;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\Combinable;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Script;
use Piwigo\Template\ScriptLoader;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\KernelContainerOverride;

// Most of this file inspects/invokes ScriptLoader's private state and
// methods via reflection for precise control over dependency graphs and
// load_mode combinations that would be awkward to construct purely
// through the public add()/addInline() API. getHeadScripts()/
// getFooterScripts()/doCombine() -> FileCombiner::combine() do not
// depend on legacy free-function side effects (is_admin(),
// PHPWG_ROOT_PATH-dependent plugin-path resolution), so the tests further
// down call them for real against real files under an isolated Paths root.

beforeEach(function (): void {
    CurrentUserTestFactory::get()->attachGlobals();
});

afterEach(function (): void {
    CurrentUserTestFactory::get()->reset();
    Kernel::reset();
});

/**
 * @return array<string, Script>
 */
function scriptLoaderRegistered(ScriptLoader $loader): array
{
    $property = new ReflectionProperty($loader, 'registered_scripts');

    /** @var array<string, Script> */
    return $property->getValue($loader);
}

function scriptLoaderTopologicalOrder(ScriptLoader $loader, string $scriptId): int
{
    $method = new ReflectionMethod($loader, 'computeScriptTopologicalOrder');

    /** @var int */
    return $method->invoke($loader, $scriptId);
}

function scriptLoaderCmp(Script $s1, Script $s2): int
{
    $method = new ReflectionMethod(ScriptLoader::class, 'cmpByModeAndOrder');

    /** @var int */
    return $method->invoke(null, $s1, $s2);
}

function file_combiner_test_rrmdir_scriptloader(string $dir): void
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
        is_dir($path) ? file_combiner_test_rrmdir_scriptloader($path) : unlink($path);
    }
    rmdir($dir);
}

// [Mutation] Remaining untested mutations after mutation testing, all
// verified genuinely inert via hand-mutation, triaged into groups:
//
// 1. RemoveBooleanCast (`(bool) X` -> `X`, 6 sites: Lines 205/236/260/
//    264/466/471): batch-removed all 6 simultaneously -- the full Unit
//    suite for this file stayed green (same universal `if((bool)X) ===
//    if(X)` PHP semantics as every other file in this campaign), but
//    `composer analyse:phpstan` on the mutated source reported exactly
//    6 errors (`if.condNotBoolean`/`elseif.condNotBoolean`/
//    `booleanNot.exprNotBoolean`/`booleanAnd.leftNotBoolean`) -- these
//    casts exist for PHPStan's own strict boolean-condition rule, not
//    for any runtime behavior difference.
//
// 2. A dead, unused parameter (Lines 298 x2, 331 x4 -- 6
//    DecrementInteger/IncrementInteger mutations total): doCombine()'s
//    own `int $load_mode` parameter is accepted but never read anywhere
//    in its body (only `$scripts`/`$accessLevelChecker` feed
//    FileCombiner's constructor) -- confirmed by reading the method
//    directly, then verified live by passing 999/888/777 instead of the
//    real 0/1/2 values simultaneously, full suite still green.
//
// 3. SmallerToSmallerOrEqual (Line 267, `$load_mode < $script->load_mode`
//    -> `<=`): when the two values are exactly equal, entering the
//    branch (mutant) assigns `$script->load_mode = $load_mode` back onto
//    itself -- an observable no-op identical to not entering it (real
//    code).
//
// 4. BreakToContinue (Line 289, getHeadScripts()'s own
//    `if ($script->load_mode > 0) { break; }`): reachable only after
//    `uasort($this->registered_scripts, self::cmpByModeAndOrder(...))`
//    sorts by load_mode ascending -- once one entry's load_mode > 0 is
//    seen, every remaining entry is guaranteed >= it (stable ascending
//    sort), so `continue`-ing past each individually converges to the
//    identical final `head_done_scripts` set as `break`-ing out
//    immediately.
//
// 5. GreaterToGreaterOrEqual/DecrementInteger (Line 327, `$script->
//    load_mode > 0`, 2 mutations): mutating to `>= 0`/`> -1` lets a
//    load_mode=0 script wrongly pass the guard, but it then gets stored
//    at `$result[$script->load_mode - 1]` = `$result[-1]` -- a real,
//    valid PHP array key, but one the very next line's `return new
//    FooterScripts($this->doCombine($result[0], ...), $this->doCombine(
//    $result[1], ...))` never reads, so it's silently discarded either
//    way.
//
// 6. TrueToFalse (Line 366, checkLoadDep()'s own async-branch
//    `$changed = true;`): see the corrected docblock on "checkLoadDep
//    converges over a multi-pass cascade..." below -- a prior session's
//    claim that a specific 5-node graph proves this mutation is real was
//    WRONG (re-verified: it doesn't catch it). Exhaustively brute-force
//    searched (all edge sets x all load-mode assignments in {1,2} x
//    multiple registration orders, up to 4 nodes) and found no
//    distinguishing graph at all. The real, structural reason: the async
//    branch's own condition is self-triggering -- any node whose own
//    load is 2 and that requires a load=2 precedent independently
//    triggers the identical downgrade the moment its own turn comes,
//    regardless of whether another node already did it first, so a
//    suppressed $changed=true here never withholds real information.
//
// 7. DecrementInteger (Line 446, computeScriptTopologicalOrder()'s own
//    `$max = 0;` before the precedents loop): only reached when
//    `count($script->precedents) > 0` (the `=== 0` case already
//    early-returns above), and the loop's own recursive call always
//    returns a non-negative int (base case 0, recursive case
//    `$max+1 >= 1`) -- so `max($max, recursive_result >= 0)` is
//    identical whether $max starts at 0 or -1.

test('add registers a new script with its load_mode and precedents', function (): void {
    $loader = new ScriptLoader();
    $loader->add('my-script', 1, ['jquery'], 'themes/default/js/foo.js');

    $registered = scriptLoaderRegistered($loader);

    expect($registered)
        ->toHaveKey('my-script')
        ->and($registered['my-script']->load_mode)->toBe(1)
        ->and($registered['my-script']->precedents)->toBe(['jquery']);
});

test('adding the same id twice merges precedents', function (): void {
    $loader = new ScriptLoader();
    $loader->add('my-script', 1, ['jquery'], 'themes/default/js/foo.js');
    $loader->add('my-script', 1, ['jquery.ui'], null);

    $registered = scriptLoaderRegistered($loader);

    expect($registered['my-script']->precedents)->toBe(['jquery', 'jquery.ui']);
});

test('adding the same id twice with a lower load_mode escalates it (lower wins)', function (): void {
    $loader = new ScriptLoader();
    $loader->add('my-script', 2, [], 'themes/default/js/foo.js');
    $loader->add('my-script', 0, [], null);

    $registered = scriptLoaderRegistered($loader);

    expect($registered['my-script']->load_mode)->toBe(0);
});

test('adding the same id twice with a higher load_mode does not downgrade it', function (): void {
    $loader = new ScriptLoader();
    $loader->add('my-script', 0, [], 'themes/default/js/foo.js');
    $loader->add('my-script', 2, [], null);

    $registered = scriptLoaderRegistered($loader);

    expect($registered['my-script']->load_mode)->toBe(0);
});

test('adding the same id twice with a higher version bumps the recorded version', function (): void {
    $loader = new ScriptLoader();
    $loader->add('my-script', 0, [], 'themes/default/js/foo.js', '1.0');
    $loader->add('my-script', 0, [], null, '2.0');

    $registered = scriptLoaderRegistered($loader);

    expect($registered['my-script']->version)->toBe('2.0');
});

test('adding the same id twice with a lower version keeps the recorded version', function (): void {
    $loader = new ScriptLoader();
    $loader->add('my-script', 0, [], 'themes/default/js/foo.js', '2.0');
    $loader->add('my-script', 0, [], null, '1.0');

    $registered = scriptLoaderRegistered($loader);

    expect($registered['my-script']->version)->toBe('2.0');
});

test('a script with no precedents has topological order 0', function (): void {
    $loader = new ScriptLoader();
    $loader->add('standalone', 0, [], 'themes/default/js/foo.js');

    expect(scriptLoaderTopologicalOrder($loader, 'standalone'))
        ->toBe(0);
});

test('a script depending on one precedent has topological order 1', function (): void {
    $loader = new ScriptLoader();
    $loader->add('base', 0, [], 'themes/default/js/base.js');
    $loader->add('dependent', 0, ['base'], 'themes/default/js/dependent.js');

    expect(scriptLoaderTopologicalOrder($loader, 'dependent'))
        ->toBe(1);
});

test('topological order follows the longest dependency chain', function (): void {
    $loader = new ScriptLoader();
    $loader->add('a', 0, [], 'themes/default/js/a.js');
    $loader->add('b', 0, ['a'], 'themes/default/js/b.js');
    $loader->add('c', 0, ['b'], 'themes/default/js/c.js');

    expect(scriptLoaderTopologicalOrder($loader, 'a'))
        ->toBe(0)
        ->and(scriptLoaderTopologicalOrder($loader, 'b'))
        ->toBe(1)
        ->and(scriptLoaderTopologicalOrder($loader, 'c'))
        ->toBe(2);
});

test('topological order takes the max across multiple precedents', function (): void {
    $loader = new ScriptLoader();
    $loader->add('shallow', 0, [], 'themes/default/js/shallow.js');
    $loader->add('deep-base', 0, [], 'themes/default/js/deep-base.js');
    $loader->add('deep', 0, ['deep-base'], 'themes/default/js/deep.js');
    $loader->add('joins-both', 0, ['shallow', 'deep'], 'themes/default/js/joins.js');

    expect(scriptLoaderTopologicalOrder($loader, 'joins-both'))
        ->toBe(2);
});

test('clear resets registered scripts and inline scripts', function (): void {
    $loader = new ScriptLoader();
    $loader->add('my-script', 0, [], 'themes/default/js/foo.js');
    $loader->addInline('console.log(1);', []);

    $loader->clear();

    expect(scriptLoaderRegistered($loader))
        ->toBe([])
        ->and($loader->inline_scripts)
        ->toBe([]);
});

test('addInline records the code verbatim', function (): void {
    $loader = new ScriptLoader();

    $loader->addInline('console.log("hello");', []);

    expect($loader->inline_scripts)
        ->toBe(['console.log("hello");']);
});

test('urlService() throws when no URL service has been set', function (): void {
    // Every call path to a private static urlService() eventually funnels
    // through doCombine() -- even a completely empty loader reaches it,
    // no filesystem or registered scripts needed. doCombine()'s own
    // AccessControl::currentForCaching() call never throws -- unlike
    // AccessControl::current(), it degrades gracefully when Kernel isn't
    // booted (see that method's own docblock) -- so this reaches
    // urlService()'s own, more specific "no URL service set" guard.
    $loader = new ScriptLoader();

    expect(fn (): array => $loader->getHeadScripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get())))
        ->toThrow(RuntimeException::class, 'ScriptLoader: no URL service set (RequestBootstrap not run yet?)');
});

test('htmlRenderer throws when the container returns an unexpected type', function (): void {
    // Same "Container returned an unexpected type" shape as
    // PresentationAccessorTest.php's own coverage, applied here via
    // Reflection since htmlRenderer() is private (and an instance method,
    // not a static accessor) -- KernelContainerOverride::withWrongTypeFor()
    // rebinds HtmlRenderingInterface::class to a plain stdClass inside a
    // real (booted) container, tripping the `instanceof` guard.
    $loader = new ScriptLoader();
    $method = new ReflectionMethod(ScriptLoader::class, 'htmlRenderer');

    expect(fn (): mixed => KernelContainerOverride::withWrongTypeFor(
        HtmlRenderingInterface::class,
        static fn (): mixed => $method->invoke($loader)
    ))->toThrow(LogicException::class, 'Container returned an unexpected type for ' . HtmlRenderingInterface::class);
});

test('urlService() throws when the container returns an unexpected type', function (): void {
    // Distinct from "urlService() throws when no URL service has been set"
    // above -- that one exercises the `! Kernel::isBooted()` guard.
    // KernelContainerOverride::with() marks Kernel booted, so this reaches
    // urlService()'s OTHER guard, the `instanceof` check right after the
    // container resolve (both guards happen to share the same message).
    $method = new ReflectionMethod(ScriptLoader::class, 'urlService');

    expect(fn (): mixed => KernelContainerOverride::withWrongTypeFor(
        UrlServiceInterface::class,
        static fn (): mixed => $method->invoke(null)
    ))->toThrow(RuntimeException::class, 'ScriptLoader: no URL service set (RequestBootstrap not run yet?)');
});

test('eventDispatcher() throws when the container returns an unexpected type', function (): void {
    $method = new ReflectionMethod(ScriptLoader::class, 'eventDispatcher');

    expect(fn (): mixed => KernelContainerOverride::withWrongTypeFor(
        EventDispatcher::class,
        static fn (): mixed => $method->invoke(null)
    ))->toThrow(RuntimeException::class, 'ScriptLoader: no EventDispatcher set (RequestBootstrap not run yet?)');
});

test('currentTemplate() throws when the container returns an unexpected type', function (): void {
    $method = new ReflectionMethod(ScriptLoader::class, 'currentTemplate');

    expect(fn (): mixed => KernelContainerOverride::withWrongTypeFor(
        CurrentTemplate::class,
        static fn (): mixed => $method->invoke(null)
    ))->toThrow(RuntimeException::class, 'ScriptLoader: no CurrentTemplate set (RequestBootstrap not run yet?)');
});

test('currentConfig() throws when the container returns an unexpected type', function (): void {
    $method = new ReflectionMethod(ScriptLoader::class, 'currentConfig');

    expect(fn (): mixed => KernelContainerOverride::withWrongTypeFor(
        CurrentConfig::class,
        static fn (): mixed => $method->invoke(null)
    ))->toThrow(RuntimeException::class, 'ScriptLoader: no CurrentConfig set (RequestBootstrap not run yet?)');
});

test('paths() throws when the container returns an unexpected type', function (): void {
    $method = new ReflectionMethod(ScriptLoader::class, 'paths');

    expect(fn (): mixed => KernelContainerOverride::withWrongTypeFor(
        Paths::class,
        static fn (): mixed => $method->invoke(null)
    ))->toThrow(RuntimeException::class, 'ScriptLoader: no Paths set (RequestBootstrap not run yet?)');
});

test('addInline downgrades a load_mode=2 dependency to load_mode=1', function (): void {
    $loader = new ScriptLoader();
    $loader->add('async-dep', 2, [], 'themes/default/js/dep.js');

    $loader->addInline('console.log(1);', ['async-dep']);

    expect(scriptLoaderRegistered($loader)['async-dep']->load_mode)->toBe(1);
});

test('addInline leaves a non-async dependency\'s load_mode untouched', function (): void {
    $loader = new ScriptLoader();
    $loader->add('sync-dep', 1, [], 'themes/default/js/dep.js');

    $loader->addInline('console.log(1);', ['sync-dep']);

    expect(scriptLoaderRegistered($loader)['sync-dep']->load_mode)->toBe(1);
});

test('addInline auto-loads an undefined but known required script', function (): void {
    $loader = new ScriptLoader();

    $loader->addInline('console.log(1);', ['jquery']);

    expect(scriptLoaderRegistered($loader))
        ->toHaveKey('jquery');
});

test('add() warns when adding a head script after the head has already been written', function (): void {
    $loader = new ScriptLoader();
    new ReflectionProperty($loader, 'did_head')
        ->setValue($loader, true);

    $caught = null;
    set_error_handler(static function (int $errno, string $errstr) use (&$caught): bool {
        $caught = $errstr;

        return true;
    }, E_USER_WARNING);
    try {
        $loader->add('late-script', 0, [], 'themes/default/js/late.js');
    } finally {
        restore_error_handler();
    }

    expect($caught)
        ->toBe('Attempt to add script late-script but the head has been written');
});

test('add() does not warn for a footer-mode script even after the head has been written', function (): void {
    $loader = new ScriptLoader();
    new ReflectionProperty($loader, 'did_head')
        ->setValue($loader, true);

    $caught = null;
    set_error_handler(static function (int $errno, string $errstr) use (&$caught): bool {
        $caught = $errstr;

        return true;
    }, E_USER_WARNING);
    try {
        $loader->add('late-footer-script', 1, [], 'themes/default/js/late.js');
    } finally {
        restore_error_handler();
    }

    expect($caught)
        ->toBeNull();
});

test('add() warns when adding a script (any load_mode) after the footer has already been written', function (): void {
    // Distinct from the two tests above: those exercise did_head's own
    // warning (and footer-mode's exemption from it) -- this is add()'s
    // OTHER warning arm, the `elseif ((bool) $this->did_footer)` branch,
    // never reached by any existing test calling add() directly (only
    // indirectly, via addInline()'s own separate did_footer warning).
    $loader = new ScriptLoader();
    new ReflectionProperty($loader, 'did_footer')
        ->setValue($loader, true);

    $caught = null;
    set_error_handler(static function (int $errno, string $errstr) use (&$caught): bool {
        $caught = $errstr;

        return true;
    }, E_USER_WARNING);
    try {
        $loader->add('late-script', 1, [], 'themes/default/js/late.js');
    } finally {
        restore_error_handler();
    }

    expect($caught)
        ->toBe('Attempt to add script late-script but the footer has been written');
});

test('add() runs fillWellKnown against a newly-registered script', function (): void {
    $loader = new ScriptLoader();

    // jquery.ui.widget is a real UI-core key with no explicit path --
    // fillWellKnown() both fills its path from $known_paths (via the
    // jquery.ui.* branch) and adds 'jquery' as a precedent.
    $loader->add('jquery.ui.widget', 0, [], null);

    $registered = scriptLoaderRegistered($loader);
    expect($registered['jquery.ui.widget']->precedents)->toBe(['jquery']);
});

test('add() auto-registers every UI core dependency when the exact known jquery.ui script is added', function (): void {
    $loader = new ScriptLoader();

    $loader->add('jquery.ui', 0, [], 'themes/default/js/ui/minified/jquery.ui.core.min.js');

    $registered = scriptLoaderRegistered($loader);
    expect($registered)
        ->toHaveKeys(['jquery.ui.widget', 'jquery.ui.position', 'jquery.ui.mouse']);
});

test('add() does not auto-register UI core dependencies for a jquery.ui script at a non-default path', function (): void {
    $loader = new ScriptLoader();

    $loader->add('jquery.ui', 0, [], 'themes/custom/js/jquery.ui.js');

    $registered = scriptLoaderRegistered($loader);
    expect($registered)
        ->not->toHaveKey('jquery.ui.widget');
});

test('add() auto-loads an undefined precedent that is a known script', function (): void {
    $loader = new ScriptLoader();

    $loader->add('my-plugin', 0, ['jquery'], 'themes/default/js/plugin.js');

    expect(scriptLoaderRegistered($loader))
        ->toHaveKey('jquery');
});

test('add() merging precedents twice with an overlapping id keeps the result deduplicated', function (): void {
    $loader = new ScriptLoader();
    $loader->add('my-script', 1, ['jquery'], 'themes/default/js/foo.js');

    $loader->add('my-script', 1, ['jquery', 'jquery.ui'], null);

    // array_unique() preserves original (now non-contiguous) keys rather
    // than reindexing -- array_values() to assert on the values alone,
    // matching the class's own intended (not just incidental) behavior.
    expect(array_values(scriptLoaderRegistered($loader)['my-script']->precedents))->toBe(['jquery', 'jquery.ui']);
});

test('add() updates the path on a second registration when given a real path', function (): void {
    $loader = new ScriptLoader();
    $loader->add('my-script', 0, [], 'themes/default/js/first.js');

    $loader->add('my-script', 0, [], 'themes/default/js/second.js');

    expect(scriptLoaderRegistered($loader)['my-script']->path)->toBe('themes/default/js/second.js');
});

test('add() ignores a null path on a second registration, keeping the original', function (): void {
    $loader = new ScriptLoader();
    $loader->add('my-script', 0, [], 'themes/default/js/first.js');

    $loader->add('my-script', 0, [], null);

    expect(scriptLoaderRegistered($loader)['my-script']->path)->toBe('themes/default/js/first.js');
});

test('add() does not bump the version when the new version is an empty/falsy string', function (): void {
    $loader = new ScriptLoader();
    $loader->add('my-script', 0, [], 'themes/default/js/foo.js', '1.0');

    $loader->add('my-script', 0, [], null, '0');

    expect(scriptLoaderRegistered($loader)['my-script']->version)->toBe('1.0');
});

test('add() does not bump the version when the existing version is false (version-locking disabled)', function (): void {
    $loader = new ScriptLoader();
    $loader->add('my-script', 0, [], 'themes/default/js/foo.js', false);

    $loader->add('my-script', 0, [], null, '5.0');

    expect(scriptLoaderRegistered($loader)['my-script']->version)->toBeFalse();
});

test('add() does not bump the version when the new version is exactly equal, not strictly greater', function (): void {
    $loader = new ScriptLoader();
    $loader->add('my-script', 0, [], 'themes/default/js/foo.js', '1.0');

    $loader->add('my-script', 0, [], null, '1.0');

    expect(scriptLoaderRegistered($loader)['my-script']->version)->toBe('1.0');
});

test('add() does not bump the version when version_compare deems it equal, even if the raw strings differ', function (): void {
    // version_compare('1.0', '01.0') === 0 -- reassigning to a
    // *textually identical* new version wouldn't distinguish `<` from
    // `<=`/`<1` (both leave the same value in place); this pair proves
    // the boundary is real by making a wrongly-triggered reassignment
    // observable.
    $loader = new ScriptLoader();
    $loader->add('my-script', 0, [], 'themes/default/js/foo.js', '1.0');

    $loader->add('my-script', 0, [], null, '01.0');

    expect(scriptLoaderRegistered($loader)['my-script']->version)->toBe('1.0');
});

test('getHeadScripts warns and excludes a script whose path was explicitly set to the empty string', function (): void {
    // set_path()'s own null/''-is-a-no-op guard means path can never
    // become '' through add() itself -- this simulates a script whose
    // path was assigned directly (bypassing set_path()), covering the
    // second element of the [null, ''] in_array() check on its own.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentUserTestFactory::get()->attachGlobals();

    $loader = new ScriptLoader();
    $loader->add('empty-path-script', 0, [], 'themes/default/js/foo.js');
    scriptLoaderRegistered($loader)['empty-path-script']
        ->path = '';

    $caught = null;
    set_error_handler(static function (int $errno, string $errstr) use (&$caught): bool {
        $caught = $errstr;

        return true;
    }, E_USER_WARNING);
    try {
        $head = $loader->getHeadScripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()));
    } finally {
        restore_error_handler();
    }

    expect($caught)
        ->toBe('Script empty-path-script has an undefined path')
        ->and($head)
        ->toBe([]);
});

test('add() does not downgrade load_mode when the new load_mode is exactly equal, not strictly lower', function (): void {
    $loader = new ScriptLoader();
    $loader->add('my-script', 1, [], 'themes/default/js/foo.js');

    $loader->add('my-script', 1, [], null);

    expect(scriptLoaderRegistered($loader)['my-script']->load_mode)->toBe(1);
});

test('getFooterScripts runs checkLoadDep when the head has not been written yet', function (): void {
    // A load_mode=2 (async) script whose precedent is also load_mode=2 --
    // checkLoadDep() downgrades the precedent to 1 so execution order
    // stays guaranteed; this only ever runs from getHeadScripts() or
    // getFooterScripts() itself (when !did_head).
    $root = sys_get_temp_dir() . '/piwigo-scriptloader-footer-checkdep-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/a.js', 'var a=1;');
    file_put_contents($root . '/themes/default/js/b.js', 'var b=1;');
    Kernel::boot(Paths::fromRoot($root));
    CurrentUserTestFactory::get()->attachGlobals();
    CurrentConfigTestFactory::get()->dataLocation = '_data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentConfigTestFactory::get()->templateCombineFiles = false;

    $loader = new ScriptLoader();
    $loader->add('precedent', 2, [], 'themes/default/js/a.js');
    $loader->add('dependent', 2, ['precedent'], 'themes/default/js/b.js');

    try {
        $loader->getFooterScripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()));

        expect(scriptLoaderRegistered($loader)['precedent']->load_mode)->toBe(1);
    } finally {
        file_combiner_test_rrmdir_scriptloader($root);
        CurrentConfigTestFactory::get()->reset();
    }
});

test('getFooterScripts skips checkLoadDep when the head was already written (getHeadScripts already ran it)', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-scriptloader-footer-skipcheckdep-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/a.js', 'var a=1;');
    file_put_contents($root . '/themes/default/js/b.js', 'var b=1;');
    Kernel::boot(Paths::fromRoot($root));
    CurrentUserTestFactory::get()->attachGlobals();
    CurrentConfigTestFactory::get()->dataLocation = '_data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentConfigTestFactory::get()->templateCombineFiles = false;

    $loader = new ScriptLoader();
    $loader->add('precedent', 2, [], 'themes/default/js/a.js');
    $loader->add('dependent', 2, ['precedent'], 'themes/default/js/b.js');
    // Force did_head=true WITHOUT actually running checkLoadDep (unlike
    // a real getHeadScripts() call) -- if getFooterScripts() ran it
    // anyway (ignoring did_head), 'precedent' would still get downgraded.
    new ReflectionProperty($loader, 'did_head')
        ->setValue($loader, true);

    try {
        $loader->getFooterScripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()));

        expect(scriptLoaderRegistered($loader)['precedent']->load_mode)->toBe(2);
    } finally {
        file_combiner_test_rrmdir_scriptloader($root);
        CurrentConfigTestFactory::get()->reset();
    }
});

test('getFooterScripts marks did_footer, which then makes addInline warn', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-scriptloader-didfooter-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentUserTestFactory::get()->attachGlobals();
    CurrentConfigTestFactory::get()->dataLocation = '_data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    $loader = new ScriptLoader();

    try {
        $loader->getFooterScripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()));

        $caught = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$caught): bool {
            $caught = $errstr;

            return true;
        }, E_USER_WARNING);
        try {
            $loader->addInline('console.log(1);', []);
        } finally {
            restore_error_handler();
        }

        expect($caught)
            ->toBe('Attempt to add inline script but the footer has been written');
    } finally {
        rmdir($root);
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
    }
});

test('getFooterScripts separates sync (load_mode=1) and async (load_mode=2) scripts into their own combined outputs', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-scriptloader-footer-split-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/sync.js', 'var s=1;');
    file_put_contents($root . '/themes/default/js/async.js', 'var a=1;');
    Kernel::boot(Paths::fromRoot($root));
    CurrentUserTestFactory::get()->attachGlobals();
    CurrentConfigTestFactory::get()->dataLocation = '_data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentConfigTestFactory::get()->templateCombineFiles = false;

    $loader = new ScriptLoader();
    $loader->add('sync-script', 1, [], 'themes/default/js/sync.js');
    $loader->add('async-script', 2, [], 'themes/default/js/async.js');

    try {
        $footerScripts = $loader->getFooterScripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()));
        $sync = $footerScripts->sync;
        $async = $footerScripts->async;

        expect($sync)
            ->toHaveCount(1)
            ->and($sync[0]->id)->toBe('sync-script')
            ->and($async)
            ->toHaveCount(1)
            ->and($async[0]->id)->toBe('async-script');
    } finally {
        file_combiner_test_rrmdir_scriptloader($root);
        CurrentConfigTestFactory::get()->reset();
    }
});

test('getFooterScripts excludes scripts already claimed by getHeadScripts', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-scriptloader-footer-excludes-head-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/head.js', 'var h=1;');
    file_put_contents($root . '/themes/default/js/footer.js', 'var f=1;');
    Kernel::boot(Paths::fromRoot($root));
    CurrentUserTestFactory::get()->attachGlobals();
    CurrentConfigTestFactory::get()->dataLocation = '_data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentConfigTestFactory::get()->templateCombineFiles = false;

    $loader = new ScriptLoader();
    $loader->add('head-script', 0, [], 'themes/default/js/head.js');
    $loader->add('footer-script', 1, [], 'themes/default/js/footer.js');

    try {
        $loader->getHeadScripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()));
        $sync = $loader->getFooterScripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()))->sync;

        expect($sync)
            ->toHaveCount(1)
            ->and($sync[0]->id)->toBe('footer-script');
    } finally {
        file_combiner_test_rrmdir_scriptloader($root);
        CurrentConfigTestFactory::get()->reset();
    }
});

test('getHeadScripts runs checkLoadDep before sorting, downgrading an async precedent of an async script', function (): void {
    // Both scripts are load_mode=2, so head_done_scripts stays empty --
    // doCombine([]) still needs a valid CurrentPaths regardless.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    // Must run after Kernel::boot() -- CurrentConfigTestFactory::get() resolves the
    // pre-boot memoized fallback instance until Kernel::boot() builds the
    // container's own (different) shared instance, and getHeadScripts()
    // below reads CurrentConfig via that container instance.
    CurrentConfigTestFactory::get()->templateCombineFiles = false;
    CurrentUserTestFactory::get()->attachGlobals();

    $loader = new ScriptLoader();
    $loader->add('precedent', 2, [], 'themes/default/js/a.js');
    $loader->add('dependent', 2, ['precedent'], 'themes/default/js/b.js');

    try {
        $loader->getHeadScripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()));

        expect(scriptLoaderRegistered($loader)['precedent']->load_mode)->toBe(1);
    } finally {
        CurrentConfigTestFactory::get()->reset();
    }
});

test('getHeadScripts computes and uses each script\'s topological order to sort within the same load_mode', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-scriptloader-head-toposort-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/base.js', 'var base=1;');
    file_put_contents($root . '/themes/default/js/dep.js', 'var dep=1;');
    Kernel::boot(Paths::fromRoot($root));
    CurrentUserTestFactory::get()->attachGlobals();
    CurrentConfigTestFactory::get()->dataLocation = '_data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentConfigTestFactory::get()->templateCombineFiles = false;

    $loader = new ScriptLoader();
    // Registered in dependency-inverted order -- 'dependent' (order 1)
    // must still sort AFTER 'base' (order 0) once uasort() actually runs.
    $loader->add('dependent', 0, ['base'], 'themes/default/js/dep.js');
    $loader->add('base', 0, [], 'themes/default/js/base.js');

    try {
        $loader->getHeadScripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()));

        $ids = array_keys(scriptLoaderRegistered($loader));
        $basePos = array_search('base', $ids, true);
        $dependentPos = array_search('dependent', $ids, true);
        // Both scripts were just registered above, so both are genuinely
        // present in $ids -- array_search()'s own false-if-not-found case
        // is unreachable here, narrowing it away for toBeLessThan()'s
        // numeric-only parameter type.
        assert(is_int($basePos) && is_int($dependentPos));
        expect($basePos)
            ->toBeLessThan($dependentPos);
    } finally {
        file_combiner_test_rrmdir_scriptloader($root);
        CurrentConfigTestFactory::get()->reset();
    }
});

test('cmpByModeAndOrder sorts a remote script before a same-mode, same-order local one, regardless of registration order', function (): void {
    // Both scripts have load_mode=0 and topological order=0 (no
    // precedents) -- cmpByModeAndOrder()'s own mode/order tiebreaks
    // both resolve equal, reaching its final `$s1->extra['order'] === 0
    // and (...) xor (...)` remote/local tiebreak. Registered in two
    // different orders across the two loaders below so the outcome can't
    // be an artifact of whichever side uasort() happens to pass as $s1.
    $root = sys_get_temp_dir() . '/piwigo-scriptloader-remote-tiebreak-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/local.js', 'var a=1;');
    Kernel::boot(Paths::fromRoot($root));
    CurrentUserTestFactory::get()->attachGlobals();
    CurrentConfigTestFactory::get()->dataLocation = '_data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentConfigTestFactory::get()->templateCombineFiles = false;

    try {
        $remoteFirst = new ScriptLoader();
        $remoteFirst->add('remote-script', 0, [], 'https://cdn.example.com/remote.js');
        $remoteFirst->add('local-script', 0, [], 'themes/default/js/local.js');
        $headA = $remoteFirst->getHeadScripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()));

        $localFirst = new ScriptLoader();
        $localFirst->add('local-script', 0, [], 'themes/default/js/local.js');
        $localFirst->add('remote-script', 0, [], 'https://cdn.example.com/remote.js');
        $headB = $localFirst->getHeadScripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()));

        expect(array_map(fn (Combinable $s): string => $s->id, $headA))
            ->toBe(['remote-script', 'local-script'])
            ->and(array_map(fn (Combinable $s): string => $s->id, $headB))
            ->toBe(['remote-script', 'local-script']);
    } finally {
        file_combiner_test_rrmdir_scriptloader($root);
        CurrentConfigTestFactory::get()->reset();
    }
});

test('getHeadScripts collects every mode=0 script up to (not including) the first non-head script', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-scriptloader-head-stops-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/head.js', 'var h=1;');
    file_put_contents($root . '/themes/default/js/footer.js', 'var f=1;');
    Kernel::boot(Paths::fromRoot($root));
    CurrentUserTestFactory::get()->attachGlobals();
    CurrentConfigTestFactory::get()->dataLocation = '_data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentConfigTestFactory::get()->templateCombineFiles = false;

    $loader = new ScriptLoader();
    $loader->add('head-script', 0, [], 'themes/default/js/head.js');
    $loader->add('footer-script', 1, [], 'themes/default/js/footer.js');

    try {
        $head = $loader->getHeadScripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()));

        expect($head)
            ->toHaveCount(1)
            ->and($head[0]->id)->toBe('head-script');
    } finally {
        file_combiner_test_rrmdir_scriptloader($root);
        CurrentConfigTestFactory::get()->reset();
    }
});

test('getHeadScripts warns and excludes a head-mode script whose path was never resolved (null, not the empty string)', function (): void {
    // add()'s own $path=null is the REAL "no known path" shape --
    // set_path()'s null/''-is-a-no-op guard means Combinable::$path can
    // never actually become '' through the public API, only stay null.
    // Confirmed live: before this file's own fix, this crashed with a
    // TypeError inside FileCombiner::combine() (is_remote() calling
    // urlIsRemote(null)) instead of hitting this warning at all.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentUserTestFactory::get()->attachGlobals();

    $loader = new ScriptLoader();
    $loader->add('no-path-script', 0, [], null);

    $caught = null;
    set_error_handler(static function (int $errno, string $errstr) use (&$caught): bool {
        $caught = $errstr;

        return true;
    }, E_USER_WARNING);
    try {
        $head = $loader->getHeadScripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()));
    } finally {
        restore_error_handler();
    }

    expect($caught)
        ->toBe('Script no-path-script has an undefined path')
        ->and($head)
        ->toBe([]);
});

test('getHeadScripts marks did_head, which then makes a subsequent head-mode add() warn', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentUserTestFactory::get()->attachGlobals();

    $loader = new ScriptLoader();

    $loader->getHeadScripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()));

    $caught = null;
    set_error_handler(static function (int $errno, string $errstr) use (&$caught): bool {
        $caught = $errstr;

        return true;
    }, E_USER_WARNING);
    try {
        $loader->add('late-script', 0, [], 'themes/default/js/late.js');
    } finally {
        restore_error_handler();
    }

    expect($caught)
        ->toBe('Attempt to add script late-script but the head has been written');
});

test('getFooterScripts sorts within the same load_mode by topological order too', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-scriptloader-footer-toposort-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/base.js', 'var base=1;');
    file_put_contents($root . '/themes/default/js/dep.js', 'var dep=1;');
    Kernel::boot(Paths::fromRoot($root));
    CurrentUserTestFactory::get()->attachGlobals();
    CurrentConfigTestFactory::get()->dataLocation = '_data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentConfigTestFactory::get()->templateCombineFiles = false;

    $loader = new ScriptLoader();
    $loader->add('dependent', 1, ['base'], 'themes/default/js/dep.js');
    $loader->add('base', 1, [], 'themes/default/js/base.js');

    try {
        $sync = $loader->getFooterScripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()))->sync;

        expect($sync)
            ->toHaveCount(2)
            ->and($sync[0]->id)->toBe('base')
            ->and($sync[1]->id)->toBe('dependent');
    } finally {
        file_combiner_test_rrmdir_scriptloader($root);
        CurrentConfigTestFactory::get()->reset();
    }
});

test('getFooterScripts excludes mode=0 scripts even when getHeadScripts was never called first', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-scriptloader-footer-nohead-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/head.js', 'var h=1;');
    file_put_contents($root . '/themes/default/js/footer.js', 'var f=1;');
    Kernel::boot(Paths::fromRoot($root));
    CurrentUserTestFactory::get()->attachGlobals();
    CurrentConfigTestFactory::get()->dataLocation = '_data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentConfigTestFactory::get()->templateCombineFiles = false;

    $loader = new ScriptLoader();
    $loader->add('head-script', 0, [], 'themes/default/js/head.js');
    $loader->add('footer-script', 1, [], 'themes/default/js/footer.js');

    try {
        // getHeadScripts() is never called -- head_done_scripts stays
        // empty, so $todo would include the mode=0 script too if it
        // weren't filtered back out by its own load_mode>0 check.
        $sync = $loader->getFooterScripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()))->sync;

        expect($sync)
            ->toHaveCount(1)
            ->and($sync[0]->id)->toBe('footer-script');
    } finally {
        file_combiner_test_rrmdir_scriptloader($root);
        CurrentConfigTestFactory::get()->reset();
    }
});

test('getFooterScripts computes topological order itself when getHeadScripts never ran, with names that disagree with alphabetical order', function (): void {
    // Real gap: a ForeachEmptyIterable/RemoveMethodCall mutation on this
    // method's own computeScriptTopologicalOrder() loop is invisible to
    // the sibling "sorts within the same load_mode by topological order
    // too" test above -- that test's own script ids ('base'/'dependent')
    // happen to ALSO sort correctly alphabetically, so even with
    // extra['order'] left entirely unset (only an "Undefined array key"
    // warning, no assertion failure), cmpByModeAndOrder()'s own id-based
    // strcmp() fallback still produces the same final order by
    // coincidence. Deliberately picking ids where topological order and
    // alphabetical order DISAGREE ('zzz-base' must sort before
    // 'aaa-dependent' despite the reverse alphabetical order) makes the
    // real assertion itself -- not just a side-channel warning -- fail
    // under the mutation.
    $root = sys_get_temp_dir() . '/piwigo-scriptloader-footer-toposort-nohead-' . bin2hex(random_bytes(8));
    mkdir($root . '/themes/default/js', 0o777, true);
    file_put_contents($root . '/themes/default/js/base.js', 'var base=1;');
    file_put_contents($root . '/themes/default/js/dep.js', 'var dep=1;');
    Kernel::boot(Paths::fromRoot($root));
    CurrentUserTestFactory::get()->attachGlobals();
    CurrentConfigTestFactory::get()->dataLocation = '_data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentConfigTestFactory::get()->templateCombineFiles = false;

    $loader = new ScriptLoader();
    $loader->add('aaa-dependent', 1, ['zzz-base'], 'themes/default/js/dep.js');
    $loader->add('zzz-base', 1, [], 'themes/default/js/base.js');

    try {
        // getHeadScripts() is never called -- computeScriptTopologicalOrder()
        // has never run for either script before this call.
        $sync = $loader->getFooterScripts(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()))->sync;

        expect($sync)
            ->toHaveCount(2)
            ->and($sync[0]->id)->toBe('zzz-base')
            ->and($sync[1]->id)->toBe('aaa-dependent');
    } finally {
        file_combiner_test_rrmdir_scriptloader($root);
        CurrentConfigTestFactory::get()->reset();
    }
});

test('checkLoadDep converges over multiple passes for a 3-level async dependency chain', function (): void {
    // c(async) <- b(async) <- a(async): the do-while must run more than
    // once for the downgrade to propagate all the way from the deepest
    // precedent up through the middle one -- a single pass only catches
    // the first hop.
    $loader = new ScriptLoader();
    $loader->add('c', 2, [], 'themes/default/js/c.js');
    $loader->add('b', 2, ['c'], 'themes/default/js/b.js');
    $loader->add('a', 2, ['b'], 'themes/default/js/a.js');

    try {
        // checkLoadDep() reaches self::urlService() for this graph (both
        // 'a'/'b' and 'b'/'c' are load_mode=2 on both sides) -- needs a
        // booted Kernel, unlike the plain-local-precedent checkLoadDep
        // tests elsewhere in this file.
        KernelContainerOverride::with([
            Paths::class => Paths::fromRoot(sys_get_temp_dir()),
        ], function () use ($loader): void {
            // Must run inside this closure -- KernelContainerOverride::with()
            // builds its own fresh container (and therefore its own fresh
            // CurrentConfig instance) before invoking it; setting this
            // outside would land on a different, discarded instance.
            CurrentConfigTestFactory::get()->templateCombineFiles = false;
            $method = new ReflectionMethod(ScriptLoader::class, 'checkLoadDep');
            $method->invoke(null, scriptLoaderRegistered($loader));
        });

        // checkLoadDep() receives the array by value but Script objects
        // by reference -- their own load_mode mutations are visible on
        // the loader's own registered copies.
        $registered = scriptLoaderRegistered($loader);
        expect($registered['b']->load_mode)->toBe(1)
            ->and($registered['c']->load_mode)->toBe(1);
    } finally {
        CurrentConfigTestFactory::get()->reset();
    }
});

test('checkLoadDep skips a precedent id that was never registered, instead of erroring', function (): void {
    // loadKnownRequiredScript() only auto-registers KNOWN precedent ids
    // (jquery/jquery.ui.*/etc.) -- an unrecognized one, like this test's,
    // stays permanently unregistered, so checkLoadDep()'s own
    // `foreach precedents` loop must skip it via its `! isset($scripts
    // [$precedent])` guard rather than fatal on the undefined index.
    $loader = new ScriptLoader();
    $loader->add('has-unknown-precedent', 1, ['totally-unrecognized-id'], 'themes/default/js/x.js');
    $registered = scriptLoaderRegistered($loader);
    expect($registered)
        ->not->toHaveKey('totally-unrecognized-id');

    $method = new ReflectionMethod(ScriptLoader::class, 'checkLoadDep');
    $method->invoke(null, $registered);

    // Reaching here (no PHP warning about an undefined array key, no
    // exception) is itself the assertion -- the script's own load_mode is
    // untouched since it has no real precedent to downgrade against.
    expect(scriptLoaderRegistered($loader)['has-unknown-precedent']->load_mode)->toBe(1);
});

test('fillWellKnown adds jquery as a precedent for any jquery.* script', function (): void {
    $loader = new ScriptLoader();

    $loader->add('jquery.custom-plugin', 0, [], 'themes/plugin/js/custom.js');

    expect(scriptLoaderRegistered($loader)['jquery.custom-plugin']->precedents)->toBe(['jquery']);
});

test('fillWellKnown does not touch precedents for a non-jquery script', function (): void {
    $loader = new ScriptLoader();

    $loader->add('my-plugin', 0, [], 'themes/plugin/js/custom.js');

    expect(scriptLoaderRegistered($loader)['my-plugin']->precedents)->toBe([]);
});

test('fillWellKnown fills in the known path for a known id registered with no path', function (): void {
    $loader = new ScriptLoader();

    $loader->add('jquery', 0, [], null);

    expect(scriptLoaderRegistered($loader)['jquery']->path)->toBe('themes/default/js/jquery.min.js');
});

test('fillWellKnown does not overwrite an already-explicit path, even for a known id', function (): void {
    $loader = new ScriptLoader();

    $loader->add('jquery', 0, [], 'themes/custom/jquery-fork.js');

    expect(scriptLoaderRegistered($loader)['jquery']->path)->toBe('themes/custom/jquery-fork.js');
});

test('fillWellKnown does not fill in a path for a known id whose path was explicitly set to the empty string', function (): void {
    $loader = new ScriptLoader();
    $loader->add('jquery', 0, [], 'themes/default/js/jquery.min.js');
    scriptLoaderRegistered($loader)['jquery']
        ->path = '';

    // Re-running fillWellKnown() directly (add() only calls it once, at
    // registration) -- with path forced to '', it must still be
    // recognized as "undefined" and re-filled, same as null.
    $method = new ReflectionMethod(ScriptLoader::class, 'fillWellKnown');
    $method->invoke(null, 'jquery', scriptLoaderRegistered($loader)['jquery']);

    expect(scriptLoaderRegistered($loader)['jquery']->path)->toBe('themes/default/js/jquery.min.js');
});

test('fillWellKnown requires jquery and jquery.ui.effect for a jquery.ui.effect-* script, filling its path from the effect dir', function (): void {
    $loader = new ScriptLoader();

    $loader->add('jquery.ui.effect-slide', 0, [], null);

    $script = scriptLoaderRegistered($loader)['jquery.ui.effect-slide'];
    expect($script->precedents)
        ->toBe(['jquery', 'jquery.ui.effect'])
        ->and($script->path)
        ->toBe('themes/default/js/ui/minified/jquery.ui.effect-slide.min.js');
});

test('fillWellKnown requires every UI core dependency for an unlisted jquery.ui.* script, filling its path from the ui dir', function (): void {
    $loader = new ScriptLoader();

    $loader->add('jquery.ui.dialog', 0, [], null);

    $script = scriptLoaderRegistered($loader)['jquery.ui.dialog'];
    expect($script->precedents)
        ->toBe(['jquery', 'jquery.ui', 'jquery.ui.widget', 'jquery.ui.position', 'jquery.ui.mouse'])
        ->and($script->path)
        ->toBe('themes/default/js/ui/minified/jquery.ui.dialog.min.js');
});

test('fillWellKnown fills a jquery.ui.effect-* path even when explicitly set to the empty string', function (): void {
    $loader = new ScriptLoader();
    $loader->add('jquery.ui.effect-slide', 0, [], 'themes/custom/slide.js');
    scriptLoaderRegistered($loader)['jquery.ui.effect-slide']
        ->path = '';

    $method = new ReflectionMethod(ScriptLoader::class, 'fillWellKnown');
    $method->invoke(null, 'jquery.ui.effect-slide', scriptLoaderRegistered($loader)['jquery.ui.effect-slide']);

    expect(scriptLoaderRegistered($loader)['jquery.ui.effect-slide']->path)
        ->toBe('themes/default/js/ui/minified/jquery.ui.effect-slide.min.js');
});

test('fillWellKnown fills a jquery.ui.* path even when explicitly set to the empty string', function (): void {
    $loader = new ScriptLoader();
    $loader->add('jquery.ui.dialog', 0, [], 'themes/custom/dialog.js');
    scriptLoaderRegistered($loader)['jquery.ui.dialog']
        ->path = '';

    $method = new ReflectionMethod(ScriptLoader::class, 'fillWellKnown');
    $method->invoke(null, 'jquery.ui.dialog', scriptLoaderRegistered($loader)['jquery.ui.dialog']);

    expect(scriptLoaderRegistered($loader)['jquery.ui.dialog']->path)
        ->toBe('themes/default/js/ui/minified/jquery.ui.dialog.min.js');
});

test('fillWellKnown only requires jquery for a jquery.ui.* script that IS an explicitly listed UI core dependency', function (): void {
    // jquery.ui.mouse is itself in $ui_core_dependencies (requiring
    // jquery, jquery.ui, jquery.ui.widget explicitly there) -- confirms
    // the isset($ui_core_dependencies[$id]) guard skips the broader
    // array_merge() fallback for ids already explicitly configured.
    $loader = new ScriptLoader();

    $loader->add('jquery.ui.mouse', 0, ['jquery', 'jquery.ui', 'jquery.ui.widget'], null);

    expect(scriptLoaderRegistered($loader)['jquery.ui.mouse']->precedents)
        ->toBe(['jquery', 'jquery.ui', 'jquery.ui.widget']);
});

test('loadKnownRequiredScript recognizes a jquery.ui.* id even when not explicitly listed', function (): void {
    $loader = new ScriptLoader();

    // jquery.ui.widget IS in $ui_core_dependencies, but jquery.ui.dialog
    // is only recognized via the str_starts_with('jquery.ui.') fallback.
    $loader->add('needs-dialog', 0, ['jquery.ui.dialog'], 'themes/default/js/foo.js');

    expect(scriptLoaderRegistered($loader))
        ->toHaveKey('jquery.ui.dialog');
});

test('loadKnownRequiredScript returns false and does not register an unknown id', function (): void {
    $loader = new ScriptLoader();
    $method = new ReflectionMethod($loader, 'loadKnownRequiredScript');

    $result = $method->invoke($loader, 'totally-unknown-script', 0);

    expect($result)
        ->toBeFalse()
        ->and(scriptLoaderRegistered($loader))
        ->not->toHaveKey('totally-unknown-script');
});

test('computeScriptTopologicalOrder succeeds at exactly the recursion limit boundary', function (): void {
    // a0..a4 (4 dependency hops): resolving a4 recurses down to a0 with
    // recursion_limiter reaching exactly 4 -- one below the `< 5` limit,
    // must resolve normally rather than fatal-erroring.
    $loader = new ScriptLoader();
    $loader->add('a0', 0, [], 'themes/default/js/a0.js');
    for ($i = 1; $i <= 4; $i++) {
        $loader->add("a{$i}", 0, ['a' . ($i - 1)], "themes/default/js/a{$i}.js");
    }

    expect(scriptLoaderTopologicalOrder($loader, 'a4'))
        ->toBe(4);
});

test('computeScriptTopologicalOrder fatal-errors exactly one level past the recursion limit', function (): void {
    $this->expectErrorLog();

    // a0..a5 (5 dependency hops): resolving a5 recurses down to a0 with
    // recursion_limiter reaching exactly 5, past the `< 5` limit.
    $loader = new ScriptLoader();
    $loader->add('a0', 0, [], 'themes/default/js/a0.js');
    for ($i = 1; $i <= 5; $i++) {
        $loader->add("a{$i}", 0, ['a' . ($i - 1)], "themes/default/js/a{$i}.js");
    }

    // HtmlService::fatalError() always calls
    // $this->errorCollector->recordFatal() then throws
    // ResponseReadyException -- caught directly here rather than via a
    // throwaway set_error_handler() (this class never triggers a PHP-level
    // error at all: trigger_error(E_USER_ERROR) was deprecated as of PHP
    // 8.4, see HtmlService::fatalError()'s own docblock for the real
    // replacement). HtmlServiceTestFactory::build() resolves the same
    // container-shared ErrorCollector instance as one of HtmlService's own
    // required constructor collaborators -- booted and reset locally,
    // scoped to this one test (other tests in this file boot their own
    // Kernel too, each independently). A real Paths is required too:
    // ErrorCollector's container factory needs a DeploymentPolicy, whose
    // own factory needs Paths to autowire.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentUserTestFactory::get()->attachGlobals();
    $errorCollector = Kernel::container()->get(ErrorCollector::class);
    if (! $errorCollector instanceof ErrorCollector) {
        throw new LogicException('Container returned an unexpected type for ' . ErrorCollector::class);
    }
    $errorCollector->drain();
    $caught = null;
    try {
        scriptLoaderTopologicalOrder($loader, 'a5');
    } catch (ResponseReadyException) {
        $collected = $errorCollector->drain();
        $caught = $collected === [] ? null : preg_replace('/^\[ERROR\] /', '', $collected[0]);
    } finally {
        Kernel::reset();
    }

    // fatalError()'s own $showTrace=true default appends a backtrace
    // after the message.
    expect($caught)
        ->toStartWith('combined script circular dependency');
});

test('computeScriptTopologicalOrder warns and returns 0 for a script id that was never registered', function (): void {
    // Reachable when a precedent id slips past add()'s own auto-load
    // attempt (an unrecognized id, same setup as checkLoadDep's sibling
    // test above) yet still gets passed into this method -- confirmed via
    // direct invocation with a bare, never-registered id.
    $loader = new ScriptLoader();

    $caught = null;
    set_error_handler(static function (int $errno, string $errstr) use (&$caught): bool {
        $caught = $errstr;

        return true;
    }, E_USER_WARNING);
    try {
        $order = scriptLoaderTopologicalOrder($loader, 'never-registered-id');
    } finally {
        restore_error_handler();
    }

    expect($caught)
        ->toBe('Undefined script never-registered-id is required by someone')
        ->and($order)
        ->toBe(0);
});

test('computeScriptTopologicalOrder does not recompute an already-memoized order', function (): void {
    $loader = new ScriptLoader();
    $loader->add('standalone', 0, [], 'themes/default/js/foo.js');
    scriptLoaderTopologicalOrder($loader, 'standalone');

    // Manually corrupt the memoized value -- if the early-return guard
    // (isset($script->extra['order'])) were skipped, this call would
    // recompute a fresh 0 instead of returning the corrupted value.
    $registered = scriptLoaderRegistered($loader);
    $registered['standalone']->extra['order'] = 999;

    expect(scriptLoaderTopologicalOrder($loader, 'standalone'))
        ->toBe(999);
});

test('addInline auto-loads a required script\'s own transitive sub-dependencies at load_mode=1 (footer-sync)', function (): void {
    // loadKnownRequiredScript() is called with a hardcoded `1` here --
    // distinct from addInline()'s OWN separate downgrade-if-already-async
    // step right below it (`if ($s->load_mode === 2) { $s->load_mode = 1;
    // }`), which only re-corrects the id literally named in $require
    // ('jquery.ui.dialog' itself) back down to 1 if it lands on 2 -- NOT
    // its own transitively-pulled-in precedents (fillWellKnown() adds
    // jquery/jquery.ui/jquery.ui.widget/jquery.ui.position/jquery.ui.mouse
    // for an unlisted jquery.ui.* id, each auto-loaded via add()'s own
    // "Try to load undefined required script" loop using this SAME `1`).
    // Asserting on 'jquery' (a sub-dependency, not the directly-required
    // id) is what actually distinguishes the hardcoded `1` from a
    // mutated 0 or 2 -- asserting on 'jquery.ui.dialog' itself wouldn't,
    // since a load_mode=2 there gets silently self-corrected regardless.
    $loader = new ScriptLoader();

    $loader->addInline('console.log(1);', ['jquery.ui.dialog']);

    expect(scriptLoaderRegistered($loader)['jquery']->load_mode)->toBe(1);
});

test('checkLoadDep needs a second pass to cascade an unconditional downgrade through an intermediate node', function (): void {
    // top(load=0) requires mid(load=2) requires bottom(load=2), registered
    // in the order mid, bottom, top -- so mid's own scan of its precedent
    // ('bottom') happens BEFORE top gets a chance to downgrade mid, using
    // mid's STALE load_mode=2 (2 > 2 is false, no downgrade yet). Only
    // once top downgrades mid (later in pass 1) does pass 2 get to use
    // mid's new load=0 to finally cascade the downgrade down to bottom.
    // templateCombineFiles=true (the default) plus purely local (non-
    // remote) paths keep the async-specific downgrade branch inert, so
    // this isolates the do-while's own repeat -- a single pass leaves
    // bottom at its stale load_mode=2 instead of the converged 0.
    CurrentConfigTestFactory::get()->templateCombineFiles = true;

    $loader = new ScriptLoader();
    $loader->add('mid', 2, ['bottom'], 'themes/default/js/mid.js');
    $loader->add('bottom', 2, [], 'themes/default/js/bottom.js');
    $loader->add('top', 0, ['mid'], 'themes/default/js/top.js');

    try {
        // 'mid'/'bottom' are both load_mode=2 -- checkLoadDep() reaches
        // self::urlService() for that pair, needing a booted Kernel.
        KernelContainerOverride::with([
            Paths::class => Paths::fromRoot(sys_get_temp_dir()),
        ], function () use ($loader): void {
            $method = new ReflectionMethod(ScriptLoader::class, 'checkLoadDep');
            $method->invoke(null, scriptLoaderRegistered($loader));
        });

        expect(scriptLoaderRegistered($loader)['bottom']->load_mode)->toBe(0);
    } finally {
        CurrentConfigTestFactory::get()->reset();
    }
});

test('checkLoadDep converges over a multi-pass cascade mixing unconditional and async downgrades', function (): void {
    // CORRECTED 2026-08-11: this test's own docblock previously claimed
    // this 5-node graph proves the async branch's own `$changed = true;`
    // (Line 366) is load-bearing -- re-verified via hand-mutation and it
    // does NOT catch that mutation; the claim was wrong. Traced why: an
    // unconditional-branch change earlier in the SAME pass (n2 downgrading
    // n4) already sets $changed=true regardless, masking the async
    // branch's own contribution in THIS graph. Went further than just
    // fixing the claim -- wrote a genuinely exhaustive brute-force search
    // (all edge sets, all load-mode assignments in {1,2}, multiple
    // registration orders, up to 4 nodes) and could not find ANY graph
    // that distinguishes Line 366's mutation. The real, structural reason:
    // the async branch's own condition (`$load === 2 && precedent's load
    // === 2`) is self-triggering -- any node whose own load is 2 and that
    // requires a load=2 precedent will independently trigger the SAME
    // downgrade the moment its own turn comes in the foreach, regardless
    // of whether some other node already did it first. There is no
    // information a suppressed $changed=true here could withhold that
    // isn't already recovered the same pass or the next, so this mutation
    // is genuinely inert, not merely hard to hit. Kept as a real multi-pass
    // convergence test in its own right, not removed.
    CurrentConfigTestFactory::get()->templateCombineFiles = false;

    $loader = new ScriptLoader();
    $loader->add('n4', 2, ['n1'], 'themes/default/js/n4.js');
    $loader->add('n1', 1, [], 'themes/default/js/n1.js');
    $loader->add('n2', 0, ['n4'], 'themes/default/js/n2.js');
    $loader->add('n3', 2, ['n2'], 'themes/default/js/n3.js');
    $loader->add('n0', 2, ['n3', 'n1'], 'themes/default/js/n0.js');

    try {
        // Several node pairs here are load_mode=2 on both sides --
        // checkLoadDep() reaches self::urlService(), needing a booted
        // Kernel.
        KernelContainerOverride::with([
            Paths::class => Paths::fromRoot(sys_get_temp_dir()),
        ], function () use ($loader): void {
            $method = new ReflectionMethod(ScriptLoader::class, 'checkLoadDep');
            $method->invoke(null, scriptLoaderRegistered($loader));
        });

        expect(scriptLoaderRegistered($loader)['n1']->load_mode)->toBe(0);
    } finally {
        CurrentConfigTestFactory::get()->reset();
    }
});

test('checkLoadDep continues past an unregistered precedent instead of abandoning the remaining ones', function (): void {
    // 'dependent' lists an unregistered ghost id BEFORE its real,
    // registered precedent -- `continue` (real code) skips only the ghost
    // and still checks 'real-precedent' afterwards; `continue`-mutated-to-
    // `break` would abandon the whole inner loop right there, leaving
    // 'real-precedent' at its original load_mode.
    $loader = new ScriptLoader();
    $loader->add('dependent', 0, ['totally-unregistered-ghost', 'real-precedent'], 'themes/default/js/dep.js');
    $loader->add('real-precedent', 2, [], 'themes/default/js/real.js');

    $method = new ReflectionMethod(ScriptLoader::class, 'checkLoadDep');
    $method->invoke(null, scriptLoaderRegistered($loader));

    expect(scriptLoaderRegistered($loader)['real-precedent']->load_mode)->toBe(0);
});

test('cmpByModeAndOrder returns the raw topological-order difference, without falling through to id comparison', function (): void {
    // s1's id sorts alphabetically AFTER s2's, but s1's own order (1) is
    // strictly less than s2's (3) -- the correct result is the NEGATIVE
    // order difference (1 - 3 = -2), not a MinusToPlus-flipped +4, and not
    // whatever strcmp('zzz-late-id', 'aaa-early-id') would give if the
    // early return were skipped (that's positive, the wrong sign, since
    // 'z' > 'a').
    $s1 = new Script(0, 'zzz-late-id', 'themes/default/js/a.js');
    $s1->extra['order'] = 1;
    $s2 = new Script(0, 'aaa-early-id', 'themes/default/js/b.js');
    $s2->extra['order'] = 3;

    expect(scriptLoaderCmp($s1, $s2))
        ->toBe(-2);
});

test('cmpByModeAndOrder falls through to strcmp when orders match but are non-zero, even if one script is remote', function (): void {
    // Both scripts share load_mode=0 and topological order=5 (non-zero) --
    // the remote/local tiebreak is only supposed to apply when order===0
    // (LogicalAndToLogicalOr on the `and` would wrongly let a non-zero
    // order fall into the remote/local ±1 branch just because the xor
    // half is true).

    $s1 = new Script(0, 'local-id', 'themes/default/js/local.js');
    $s1->extra['order'] = 5;
    $s2 = new Script(0, 'remote-id', 'https://cdn.example.com/x.js');
    $s2->extra['order'] = 5;

    expect(scriptLoaderCmp($s1, $s2))
        ->toBe(strcmp('local-id', 'remote-id'));
});

test('cmpByModeAndOrder sorts a remote script strictly before a local one at the same mode/order (direct comparator invocation)', function (): void {
    // Complements the existing uasort()-based remote-tiebreak test --
    // asserts the EXACT -1 return value directly, closing both an
    // IncrementInteger mutant on that literal (-1 -> 0, a tie instead of
    // "before") and a DecrementInteger one (-1 -> -2, still negative for
    // sorting purposes but a different pinned value here).

    $remote = new Script(0, 'remote-id', 'https://cdn.example.com/x.js');
    $remote->extra['order'] = 0;
    $local = new Script(0, 'local-id', 'themes/default/js/local.js');
    $local->extra['order'] = 0;

    // order===0 on both sides reaches the remote/local xor tiebreak,
    // which calls self::urlService() -- needs a booted Kernel.
    expect(KernelContainerOverride::with([
        Paths::class => Paths::fromRoot(sys_get_temp_dir()),
    ], fn (): int => scriptLoaderCmp($remote, $local)))->toBe(-1);
});

test('cmpByModeAndOrder sorts a local script strictly after a remote one at the same mode/order (direct comparator invocation)', function (): void {
    // The mirror image of the test above, exercising the ternary's OTHER
    // branch (`$s1` is the local one this time) -- pins the exact +1
    // return value, closing an IncrementInteger mutant on that literal
    // (1 -> 2).

    $remote = new Script(0, 'remote-id', 'https://cdn.example.com/x.js');
    $remote->extra['order'] = 0;
    $local = new Script(0, 'local-id', 'themes/default/js/local.js');
    $local->extra['order'] = 0;

    // order===0 on both sides reaches the remote/local xor tiebreak,
    // which calls self::urlService() -- needs a booted Kernel.
    expect(KernelContainerOverride::with([
        Paths::class => Paths::fromRoot(sys_get_temp_dir()),
    ], fn (): int => scriptLoaderCmp($local, $remote)))->toBe(1);
});
