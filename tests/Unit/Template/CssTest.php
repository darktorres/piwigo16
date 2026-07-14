<?php

declare(strict_types=1);

use Piwigo\Template\Css;

test('constructor sets order and inherits Combinable fields', function (): void {
    $css = new Css('my-css', 'themes/default/css/foo.css', '2.0', 5);

    expect($css->order)->toBe(5)
        ->and($css->id)->toBe('my-css')
        ->and($css->path)->toBe('themes/default/css/foo.css')
        ->and($css->version)->toBe('2.0');
});

test('constructor defaults order to 0', function (): void {
    $css = new Css('my-css', 'themes/default/css/foo.css');

    expect($css->order)->toBe(0);
});
