<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\AboutPageContext;

test('toArray omits THEME_ABOUT entirely when null', function (): void {
    expect((new AboutPageContext(aboutMessage: '<p>About</p>', themeAbout: null))->toArray())
        ->toBe(['ABOUT_MESSAGE' => '<p>About</p>']);
});

test('toArray includes THEME_ABOUT when set', function (): void {
    expect((new AboutPageContext(aboutMessage: '<p>About</p>', themeAbout: '<p>Theme about</p>'))->toArray())
        ->toBe(['ABOUT_MESSAGE' => '<p>About</p>', 'THEME_ABOUT' => '<p>Theme about</p>']);
});
