<?php

declare(strict_types=1);

use Piwigo\Template\Script;

test('constructor sets loadMode, precedents, and initializes extra empty', function (): void {
    $script = new Script(1, 'my-script', 'themes/default/js/foo.js', '1.0', ['jquery']);

    expect($script->loadMode)
        ->toBe(1)
        ->and($script->precedents)
        ->toBe(['jquery'])
        ->and($script->extra)
        ->toBe([])
        ->and($script->id)
        ->toBe('my-script')
        ->and($script->path)
        ->toBe('themes/default/js/foo.js');
});

test('constructor defaults precedents to an empty array', function (): void {
    $script = new Script(0, 'my-script', 'themes/default/js/foo.js');

    expect($script->precedents)
        ->toBe([]);
});
