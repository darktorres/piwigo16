<?php

declare(strict_types=1);

namespace Piwigo\Tag\Projection;

use Piwigo\Common\ValueObject\TagId;

/**
 * {@see \Piwigo\Tag\TagRepository::findTagIdsByImageIds()}'s own row
 * shape -- {@see \Piwigo\Tag\TagService::getImageTagIds()}'s real (and
 * only) consumer.
 */
final readonly class ImageTagLink
{
    public function __construct(
        public int $imageId,
        public TagId $tagId,
    ) {}
}
