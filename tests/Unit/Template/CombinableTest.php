<?php

declare(strict_types=1);

use Piwigo\Template\Combinable;
use Piwigo\Tests\Support\UrlServiceTestFactory;

test('constructor sets id, path and version', function (): void {
    $combinable = new Combinable('my-id', 'themes/default/js/foo.js', '1.2.3');

    expect($combinable->id)
        ->toBe('my-id')
        ->and($combinable->path)
        ->toBe('themes/default/js/foo.js')
        ->and($combinable->version)
        ->toBe('1.2.3');
});

test('constructor defaults version to 0 and is_template to false', function (): void {
    $combinable = new Combinable('my-id', 'themes/default/js/foo.js');

    expect($combinable->version)
        ->toBe('0')
        ->and($combinable->is_template)
        ->toBeFalse();
});

test('a null path leaves path unset (well-known path filled in later)', function (): void {
    $combinable = new Combinable('jquery.ui.widget', null);

    // setPath(null)'s own documented no-op leaves $path at its real
    // ?string $path = null default -- isset() on a null property value is
    // false regardless, which is exactly what this test verifies (a
    // well-known path gets filled in later by ScriptLoader::fillWellKnown()).
    expect(isset($combinable->path))
        ->toBeFalse();
});

test('setPath is a no-op for an empty path', function (): void {
    $combinable = new Combinable('my-id', 'themes/default/js/foo.js');

    $combinable->setPath('');
    $combinable->setPath(null);

    expect($combinable->path)
        ->toBe('themes/default/js/foo.js');
});

test('setPath overwrites a non-empty path', function (): void {
    $combinable = new Combinable('my-id', 'themes/default/js/foo.js');

    $combinable->setPath('themes/default/js/bar.js');

    expect($combinable->path)
        ->toBe('themes/default/js/bar.js');
});

test('isRemote is true for an absolute URL', function (): void {
    $combinable = new Combinable('my-id', 'https://cdn.example.com/foo.js');

    expect($combinable->isRemote(UrlServiceTestFactory::build()))->toBeTrue();
});

test('isRemote is true for a protocol-relative URL', function (): void {
    $combinable = new Combinable('my-id', '//cdn.example.com/foo.js');

    expect($combinable->isRemote(UrlServiceTestFactory::build()))->toBeTrue();
});

test('isRemote is false for a local path', function (): void {
    $combinable = new Combinable('my-id', 'themes/default/js/foo.js');

    expect($combinable->isRemote(UrlServiceTestFactory::build()))->toBeFalse();
});
