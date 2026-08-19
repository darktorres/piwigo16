<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\ElementSetRanksHeaderPageContext;
use Piwigo\Admin\Projection\ElementSetThumbnailRow;

test('toArray flattens every property to its real Latte template variable name', function (): void {
    $context = new ElementSetRanksHeaderPageContext(
        categoriesNav: '<a href="/admin.php">Home</a>',
        formAction: '/admin.php',
        pwgToken: 'token123',
        imageOrderOptions: [
            '' => '',
            'file ASC' => 'File name, A to Z',
        ],
        imageOrderChoice: 'rank',
        thumbnails: [
            new ElementSetThumbnailRow(id: 5, name: 'Sunset', tnSrc: '/i/5.jpg', rank: 20, size: [120, 80]),
        ],
        imageOrder: ['file ASC', '', ''],
    );

    expect($context->toArray())
        ->toBe([
            'CATEGORIES_NAV' => '<a href="/admin.php">Home</a>',
            'F_ACTION' => '/admin.php',
            'CSRF_TOKEN' => 'token123',
            'image_order_options' => [
                '' => '',
                'file ASC' => 'File name, A to Z',
            ],
            'image_order_choice' => 'rank',
            'thumbnails' => [[
                'ID' => 5,
                'NAME' => 'Sunset',
                'TN_SRC' => '/i/5.jpg',
                'RANK' => 20,
                'SIZE' => [120, 80],
            ]],
            'image_order' => ['file ASC', '', ''],
        ]);
});

test('toArray includes an empty thumbnails list (not omitted)', function (): void {
    $context = new ElementSetRanksHeaderPageContext(
        categoriesNav: '<a href="/admin.php">Home</a>',
        formAction: '/admin.php',
        pwgToken: 'token123',
        imageOrderOptions: [],
        imageOrderChoice: 'default',
        thumbnails: [],
        imageOrder: ['', '', ''],
    );

    expect($context->toArray()['thumbnails'])->toBe([]);
});
