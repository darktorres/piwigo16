<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\NotificationPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new NotificationPageContext(
        feedUrl: '/feed.php?feed=1',
        feedImageOnlyUrl: '/feed.php?feed=1&amp;image_only',
    );

    expect($context->toArray())->toBe([
        'U_FEED' => '/feed.php?feed=1',
        'U_FEED_IMAGE_ONLY' => '/feed.php?feed=1&amp;image_only',
    ]);
});
