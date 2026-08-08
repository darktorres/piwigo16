<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryDefaultThumbnailsPageContext;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\SizingParams;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $derivativeParams = new DerivativeParams(new SizingParams([100, 100]));
    $context = new CategoryDefaultThumbnailsPageContext(
        derivativeParams: $derivativeParams,
        maxRequests: 4,
        showThumbnailCaption: true,
        thumbnails: [['TN_ALT' => 'photo1']],
    );

    expect($context->toArray())->toBe([
        'derivative_params' => $derivativeParams,
        'maxRequests' => 4,
        'SHOW_THUMBNAIL_CAPTION' => true,
        'thumbnails' => [['TN_ALT' => 'photo1']],
    ]);
});
