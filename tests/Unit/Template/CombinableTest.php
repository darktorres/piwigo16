<?php

declare(strict_types=1);

use Piwigo\Template\Combinable;

// Combinable::is_remote() calls the free function url_is_remote() --
// always available now via composer autoload.files
// (src/Piwigo/Url/functions.php, P23 batch 8c), no explicit require needed.

test('constructor sets id, path and version', function (): void {
    $combinable = new Combinable('my-id', 'themes/default/js/foo.js', '1.2.3');

    expect($combinable->id)->toBe('my-id')
        ->and($combinable->path)->toBe('themes/default/js/foo.js')
        ->and($combinable->version)->toBe('1.2.3');
});

test('constructor defaults version to 0 and is_template to false', function (): void {
    $combinable = new Combinable('my-id', 'themes/default/js/foo.js');

    expect($combinable->version)->toBe('0')
        ->and($combinable->is_template)->toBeFalse();
});

test('a null path leaves path unset (well-known path filled in later)', function (): void {
    $combinable = new Combinable('jquery.ui.widget', null);

    expect(isset($combinable->path))->toBeFalse();
});

test('set_path is a no-op for an empty path', function (): void {
    $combinable = new Combinable('my-id', 'themes/default/js/foo.js');

    $combinable->set_path('');
    $combinable->set_path(null);

    expect($combinable->path)->toBe('themes/default/js/foo.js');
});

test('set_path overwrites a non-empty path', function (): void {
    $combinable = new Combinable('my-id', 'themes/default/js/foo.js');

    $combinable->set_path('themes/default/js/bar.js');

    expect($combinable->path)->toBe('themes/default/js/bar.js');
});

test('is_remote is true for an absolute URL', function (): void {
    $combinable = new Combinable('my-id', 'https://cdn.example.com/foo.js');

    expect($combinable->is_remote())->toBeTrue();
});

test('is_remote is true for a protocol-relative URL', function (): void {
    $combinable = new Combinable('my-id', '//cdn.example.com/foo.js');

    expect($combinable->is_remote())->toBeTrue();
});

test('is_remote is false for a local path', function (): void {
    $combinable = new Combinable('my-id', 'themes/default/js/foo.js');

    expect($combinable->is_remote())->toBeFalse();
});
