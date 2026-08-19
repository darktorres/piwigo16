<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\PictureFormatRow;
use Piwigo\Admin\Projection\PictureFormatsPageContext;

test('toArray flattens every property to its real Latte template variable name', function (): void {
    $context = new PictureFormatsPageContext(
        addFormatsUrl: '/admin.php?page=photos_add&formats=1',
        imgSquareSrc: '/i.php?/photo1-sq.jpg',
        formats: [
            new PictureFormatRow(
                formatId: 7,
                imageId: 1,
                ext: 'webp',
                filesize: 12.34,
                downloadUrl: 'action.php?format=7&amp;download',
                label: 'WEBP',
            ),
        ],
        pwgToken: 'token123',
    );

    expect($context->toArray())
        ->toBe([
            'ADD_FORMATS_URL' => '/admin.php?page=photos_add&formats=1',
            'IMG_SQUARE_SRC' => '/i.php?/photo1-sq.jpg',
            'FORMATS' => [[
                'format_id' => 7,
                'image_id' => 1,
                'ext' => 'webp',
                'filesize' => 12.34,
                'download_url' => 'action.php?format=7&amp;download',
                'label' => 'WEBP',
            ]],
            'CSRF_TOKEN' => 'token123',
        ]);
});
