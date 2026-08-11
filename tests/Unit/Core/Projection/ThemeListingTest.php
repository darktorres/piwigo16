<?php

declare(strict_types=1);

use Piwigo\Core\Projection\ThemeListing;

test('constructs with the given id and name', function (): void {
    $theme = new ThemeListing('default', 'Default Theme');

    expect($theme->id)
        ->toBe('default')
        ->and($theme->name)
        ->toBe('Default Theme');
});

test('toArray round-trips the id and name', function (): void {
    $theme = new ThemeListing('elegant', 'Elegant');

    expect($theme->toArray())
        ->toBe([
            'id' => 'elegant',
            'name' => 'Elegant',
        ]);
});
