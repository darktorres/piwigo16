<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\PictureFormatsPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new PictureFormatsPageContext(
        addFormatsUrl: '/admin.php?page=photos_add&formats=1',
        imgSquareSrc: '/i.php?/photo1-sq.jpg',
        formats: [[
            'ext' => 'webp',
            'label' => 'WEBP',
        ]],
        pwgToken: 'token123',
    );

    expect($context->toArray())
        ->toBe([
            'ADD_FORMATS_URL' => '/admin.php?page=photos_add&formats=1',
            'IMG_SQUARE_SRC' => '/i.php?/photo1-sq.jpg',
            'FORMATS' => [[
                'ext' => 'webp',
                'label' => 'WEBP',
            ]],
            'CSRF_TOKEN' => 'token123',
        ]);
});
