<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\PictureCoiPageContext;

test('toArray omits the coi key entirely when null', function (): void {
    $context = new PictureCoiPageContext(title: 'Photo 1', alt: 'photo1.jpg', imgUrl: '/i.php?/photo1.jpg', coi: null);

    expect($context->toArray())->toBe([
        'TITLE' => 'Photo 1',
        'ALT' => 'photo1.jpg',
        'U_IMG' => '/i.php?/photo1.jpg',
    ]);
});

test('toArray includes the coi key when set', function (): void {
    $context = new PictureCoiPageContext(
        title: 'Photo 1',
        alt: 'photo1.jpg',
        imgUrl: '/i.php?/photo1.jpg',
        coi: ['l' => 0.1, 't' => 0.2, 'r' => 0.3, 'b' => 0.4],
    );

    expect($context->toArray())->toBe([
        'TITLE' => 'Photo 1',
        'ALT' => 'photo1.jpg',
        'U_IMG' => '/i.php?/photo1.jpg',
        'coi' => ['l' => 0.1, 't' => 0.2, 'r' => 0.3, 'b' => 0.4],
    ]);
});
