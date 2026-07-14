<?php

declare(strict_types=1);

use Piwigo\Template\Css;
use Piwigo\Template\CssLoader;

// Inspects CssLoader's private $registered_css via reflection rather than
// going through get_css() -> FileCombiner::combine(), which cascades
// through several legacy free functions with top-level side effects
// (is_admin() -> ... -> functions_user.inc.php's own top-level
// add_event_handler() call, which itself needs functions_plugins.inc.php
// loaded first). CssLoader::add()'s dedup/ordering logic -- what's under
// test here -- doesn't need any of that machinery.

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

    expect($registered)->toHaveKey('my-css')
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

    expect($registered)->toHaveCount(1)
        ->and($registered['my-css']->path)->toBe('themes/default/css/v2.css');
});

test('adding the same id twice with a lower order keeps the first registration', function (): void {
    $loader = new CssLoader();
    $loader->add('my-css', 'themes/default/css/v1.css', order: 5);
    $loader->add('my-css', 'themes/default/css/v2.css', order: 0);

    $registered = cssLoaderRegisteredCss($loader);

    expect($registered)->toHaveCount(1)
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

    expect(cssLoaderRegisteredCss($loader))->toBe([]);
});
