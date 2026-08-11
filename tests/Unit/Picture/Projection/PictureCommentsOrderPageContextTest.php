<?php

declare(strict_types=1);

use Piwigo\Picture\Projection\PictureCommentsOrderPageContext;

test('toArray flattens both order fields', function (): void {
    expect(new PictureCommentsOrderPageContext(orderUrl: '/picture.php?comments_order=DESC', orderTitle: 'Show latest comments first')->toArray())
        ->toBe([
            'COMMENTS_ORDER_URL' => '/picture.php?comments_order=DESC',
            'COMMENTS_ORDER_TITLE' => 'Show latest comments first',
        ]);
});
