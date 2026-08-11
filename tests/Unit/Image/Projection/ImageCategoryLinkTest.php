<?php

declare(strict_types=1);

use Piwigo\Image\Projection\ImageCategoryLink;

test('constructs with distinct values for every property', function (): void {
    $row = new ImageCategoryLink(4, 2);

    expect($row->imageId)
        ->toBe(4)
        ->and($row->categoryId)
        ->toBe(2);
});
