<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Tag\ImageTagEntity;

test('constructs with distinct values for every property', function (): void {
    $imageTag = new ImageTagEntity(imageId: ImageId::from(310), tagId: TagId::from(64));

    expect($imageTag->imageId)
        ->toEqual(ImageId::from(310))
        ->and($imageTag->tagId)
        ->toEqual(TagId::from(64));
});
