<?php

declare(strict_types=1);

use Piwigo\Template\Script;
use Piwigo\Template\ScriptLoader;

// Inspects/invokes ScriptLoader's private state and methods via reflection
// rather than going through get_head_scripts()/get_footer_scripts() ->
// FileCombiner::combine(), which cascades through several legacy free
// functions with top-level side effects (is_admin(), and
// include/functions.inc.php's own top-level define(PHPWG_PLUGINS_PATH, ...)
// which needs PHPWG_ROOT_PATH already defined -- exactly the kind of
// legacy-bootstrap coupling this whole audit is about). add()'s dependency-
// merging and compute_script_topological_order()'s ordering logic -- what's
// under test here -- don't need any of that machinery.

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
    $method = new ReflectionMethod($loader, 'compute_script_topological_order');

    /** @var int */
    return $method->invoke($loader, $scriptId);
}

test('add registers a new script with its load_mode and precedents', function (): void {
    $loader = new ScriptLoader();
    $loader->add('my-script', 1, ['jquery'], 'themes/default/js/foo.js');

    $registered = scriptLoaderRegistered($loader);

    expect($registered)->toHaveKey('my-script')
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

    expect(scriptLoaderTopologicalOrder($loader, 'standalone'))->toBe(0);
});

test('a script depending on one precedent has topological order 1', function (): void {
    $loader = new ScriptLoader();
    $loader->add('base', 0, [], 'themes/default/js/base.js');
    $loader->add('dependent', 0, ['base'], 'themes/default/js/dependent.js');

    expect(scriptLoaderTopologicalOrder($loader, 'dependent'))->toBe(1);
});

test('topological order follows the longest dependency chain', function (): void {
    $loader = new ScriptLoader();
    $loader->add('a', 0, [], 'themes/default/js/a.js');
    $loader->add('b', 0, ['a'], 'themes/default/js/b.js');
    $loader->add('c', 0, ['b'], 'themes/default/js/c.js');

    expect(scriptLoaderTopologicalOrder($loader, 'a'))->toBe(0)
        ->and(scriptLoaderTopologicalOrder($loader, 'b'))->toBe(1)
        ->and(scriptLoaderTopologicalOrder($loader, 'c'))->toBe(2);
});

test('topological order takes the max across multiple precedents', function (): void {
    $loader = new ScriptLoader();
    $loader->add('shallow', 0, [], 'themes/default/js/shallow.js');
    $loader->add('deep-base', 0, [], 'themes/default/js/deep-base.js');
    $loader->add('deep', 0, ['deep-base'], 'themes/default/js/deep.js');
    $loader->add('joins-both', 0, ['shallow', 'deep'], 'themes/default/js/joins.js');

    expect(scriptLoaderTopologicalOrder($loader, 'joins-both'))->toBe(2);
});

test('clear resets registered scripts and inline scripts', function (): void {
    $loader = new ScriptLoader();
    $loader->add('my-script', 0, [], 'themes/default/js/foo.js');
    $loader->add_inline('console.log(1);', []);

    $loader->clear();

    expect(scriptLoaderRegistered($loader))->toBe([])
        ->and($loader->inline_scripts)->toBe([]);
});

test('add_inline records the code verbatim', function (): void {
    $loader = new ScriptLoader();

    $loader->add_inline('console.log("hello");', []);

    expect($loader->inline_scripts)->toBe(['console.log("hello");']);
});
