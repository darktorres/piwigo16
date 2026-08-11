<?php

declare(strict_types=1);

use Piwigo\Template\Css;
use Piwigo\Template\CssLoader;

// Inspects CssLoader's private $registered_css via reflection rather than
// going through get_css() -> FileCombiner::combine(), which cascades
// through several legacy free functions with top-level side effects
// (is_admin() -> ... -> functions_user.inc.php's own top-level
// add_event_handler() call). CssLoader::add()'s dedup/ordering logic --
// what's under test here -- doesn't need any of that machinery.

/**
 * @return array<string, Css>
 */
function cssLoaderRegisteredCss(CssLoader $loader): array
{
    $property = new ReflectionProperty($loader, 'registered_css');

    /** @var array<string, Css> */
    return $property->getValue($loader);
}

test('add registers a new css by id', function (): void {
    $loader = new CssLoader();
    $loader->add('my-css', 'themes/default/css/foo.css');

    $registered = cssLoaderRegisteredCss($loader);

    expect($registered)
        ->toHaveKey('my-css')
        ->and($registered['my-css']->path)->toBe('themes/default/css/foo.css');
});

test('declaration order is preserved via the internal counter when order is equal', function (): void {
    $loader = new CssLoader();
    $loader->add('first', 'themes/default/css/first.css');
    $loader->add('second', 'themes/default/css/second.css');

    $registered = cssLoaderRegisteredCss($loader);

    expect($registered['first']->order)->toBeLessThan($registered['second']->order);
});

test('a higher explicit order sorts after a lower one regardless of declaration order', function (): void {
    $loader = new CssLoader();
    $loader->add('declared-first-low-priority', 'themes/default/css/a.css', order: 0);
    $loader->add('declared-second-high-priority', 'themes/default/css/b.css', order: 5);

    $registered = cssLoaderRegisteredCss($loader);

    expect($registered['declared-first-low-priority']->order)
        ->toBeLessThan($registered['declared-second-high-priority']->order);
});

test('adding the same id twice with a higher order replaces the first registration', function (): void {
    $loader = new CssLoader();
    $loader->add('my-css', 'themes/default/css/v1.css', order: 0);
    $loader->add('my-css', 'themes/default/css/v2.css', order: 5);

    $registered = cssLoaderRegisteredCss($loader);

    expect($registered)
        ->toHaveCount(1)
        ->and($registered['my-css']->path)->toBe('themes/default/css/v2.css');
});

test('adding the same id twice with a lower order keeps the first registration', function (): void {
    $loader = new CssLoader();
    $loader->add('my-css', 'themes/default/css/v1.css', order: 5);
    $loader->add('my-css', 'themes/default/css/v2.css', order: 0);

    $registered = cssLoaderRegisteredCss($loader);

    expect($registered)
        ->toHaveCount(1)
        ->and($registered['my-css']->path)->toBe('themes/default/css/v1.css');
});

test('adding the same id twice with a higher version replaces the first registration', function (): void {
    $loader = new CssLoader();
    $loader->add('my-css', 'themes/default/css/v1.css', version: '1.0');
    $loader->add('my-css', 'themes/default/css/v2.css', version: '2.0');

    $registered = cssLoaderRegisteredCss($loader);

    expect($registered['my-css']->path)->toBe('themes/default/css/v2.css');
});

test('adding the same id twice with version=false always replaces the first registration', function (): void {
    $loader = new CssLoader();
    $loader->add('my-css', 'themes/default/css/v1.css', version: '9.9');
    $loader->add('my-css', 'themes/default/css/v2.css', version: false);

    $registered = cssLoaderRegisteredCss($loader);

    expect($registered['my-css']->path)->toBe('themes/default/css/v2.css');
});

test('clear resets registered CSS', function (): void {
    $loader = new CssLoader();
    $loader->add('my-css', 'themes/default/css/foo.css');

    $loader->clear();

    expect(cssLoaderRegisteredCss($loader))
        ->toBe([]);
});

test('clear resets the declaration counter back to 0, not just the registered array', function (): void {
    $loader = new CssLoader();
    $loader->add('first', 'a.css');
    $loader->add('second', 'b.css');

    $loader->clear();
    $loader->add('after-clear', 'c.css');

    $registered = cssLoaderRegisteredCss($loader);

    expect($registered['after-clear']->order)->toBe(0);
});

test('add computes order as order*1000 plus the declaration counter', function (): void {
    $loader = new CssLoader();
    $loader->add('a', 'a.css', order: 2);
    $loader->add('b', 'b.css', order: 2);

    $registered = cssLoaderRegisteredCss($loader);

    expect($registered['a']->order)->toBe(2000)
        ->and($registered['b']->order)->toBe(2001);
});

test('get_css sorts registered CSS by order before combining', function (): void {
    $loader = new CssLoader();
    $loader->add('high-priority', 'high.css', order: 5);
    $loader->add('low-priority', 'low.css', order: 0);

    $method = new ReflectionMethod($loader, 'sortedRegisteredCss');
    /** @var array<string, Css> $sorted */
    $sorted = $method->invoke($loader);

    expect(array_keys($sorted))
        ->toBe(['low-priority', 'high-priority']);
});

test('shouldReplace requires the existing order to be strictly less than order*1000', function (): void {
    $method = new ReflectionMethod(CssLoader::class, 'shouldReplace');

    // order=3 -> threshold 3*1000=3000. Exactly-equal existing (3000) must
    // NOT replace (strict <, not <=, and distinguishes a *1001 off-by-one
    // which would wrongly say true here); one below (2999) must replace,
    // and also distinguishes a *999 off-by-one, which would wrongly say
    // false there since 2999 < 2997 is false. No dependency on the
    // declaration counter, unlike testing this through add() directly.
    expect($method->invoke(null, new Css('id', 'path', '0', 3000), 3, '0'))
        ->toBeFalse();
    expect($method->invoke(null, new Css('id', 'path', '0', 2999), 3, '0'))
        ->toBeTrue();
});

test('cmp_by_order subtracts orders, not adds them', function (): void {
    // Both orders non-zero and positive so addition and subtraction
    // produce genuinely opposite-signed results (either operand being 0
    // would leave the sign unchanged either way).
    $method = new ReflectionMethod(CssLoader::class, 'cmp_by_order');

    expect($method->invoke(null, new Css('a', 'a.css', '0', 3), new Css('b', 'b.css', '0', 5)))
        ->toBeLessThan(0);
    expect($method->invoke(null, new Css('a', 'a.css', '0', 5), new Css('b', 'b.css', '0', 3)))
        ->toBeGreaterThan(0);
});

test('shouldReplace always replaces when either the existing or new version is false', function (): void {
    $method = new ReflectionMethod(CssLoader::class, 'shouldReplace');

    expect($method->invoke(null, new Css('id', 'path', false, 5000), 0, '1.0'))
        ->toBeTrue();
    expect($method->invoke(null, new Css('id', 'path', '1.0', 5000), 0, false))
        ->toBeTrue();
    expect($method->invoke(null, new Css('id', 'path', '1.0', 5000), 0, '1.0'))
        ->toBeFalse();
});
