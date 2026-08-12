<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryCatsPageContext;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\SizingParams;

test('toArray flattens every property to its real Latte template variable name', function (): void {
    $derivativeParams = new DerivativeParams(new SizingParams([100, 100]));
    $context = new CategoryCatsPageContext(
        maxRequests: 4,
        categoryThumbnails: [[
            'id' => 1,
        ]],
        derivativeParams: $derivativeParams,
    );

    expect($context->toArray())
        ->toBe([
            'maxRequests' => 4,
            'category_thumbnails' => [[
                'id' => 1,
            ]],
            'derivative_params' => $derivativeParams,
        ]);
});
