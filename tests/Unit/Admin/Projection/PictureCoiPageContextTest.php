<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\CroppedDerivativeLink;
use Piwigo\Admin\Projection\PictureCoiPageContext;
use Piwigo\Image\Projection\CenterOfInterest;

test('toArray omits the coi key entirely when null', function (): void {
    $context = new PictureCoiPageContext(title: 'Photo 1', alt: 'photo1.jpg', imgUrl: '/i.php?/photo1.jpg', coi: null, croppedDerivatives: []);

    expect($context->toArray())
        ->toBe([
            'TITLE' => 'Photo 1',
            'ALT' => 'photo1.jpg',
            'U_IMG' => '/i.php?/photo1.jpg',
            'cropped_derivatives' => [],
        ]);
});

test('toArray includes the coi key and cropped derivatives when set', function (): void {
    $context = new PictureCoiPageContext(
        title: 'Photo 1',
        alt: 'photo1.jpg',
        imgUrl: '/i.php?/photo1.jpg',
        coi: new CenterOfInterest(l: 0.1, t: 0.2, r: 0.3, b: 0.4),
        croppedDerivatives: [
            new CroppedDerivativeLink(uImg: '/i.php?/photo1-sq.jpg', htmSize: 'width="120" height="120"'),
        ],
    );

    expect($context->toArray())
        ->toBe([
            'TITLE' => 'Photo 1',
            'ALT' => 'photo1.jpg',
            'U_IMG' => '/i.php?/photo1.jpg',
            'cropped_derivatives' => [[
                'U_IMG' => '/i.php?/photo1-sq.jpg',
                'HTM_SIZE' => 'width="120" height="120"',
            ]],
            'coi' => [
                'l' => 0.1,
                't' => 0.2,
                'r' => 0.3,
                'b' => 0.4,
            ],
        ]);
});
