<?php

declare(strict_types=1);

use Piwigo\Tag\ImageTagEntity;

test('constructs with distinct values for every property', function (): void {
    $imageTag = new ImageTagEntity(imageId: 310, tagId: 64);

    expect($imageTag->imageId)->toBe(310)
        ->and($imageTag->tagId)->toBe(64);
});
