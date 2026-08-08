<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\TagId;
use Piwigo\Tag\Projection\ImageTagLink;

test('constructs with the given image id and tag id', function (): void {
    $link = new ImageTagLink(4, TagId::from(2));

    expect($link->imageId)->toBe(4)
        ->and($link->tagId)->toBeInstanceOf(TagId::class)
        ->and($link->tagId->value)->toBe(2);
});
