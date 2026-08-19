<?php

declare(strict_types=1);

namespace Piwigo\Tag\Projection;

/**
 * {@see \Piwigo\Tag\TagRepository::massInsertImageTags()}'s own
 * `image_tag` insert row -- raw ints, not {@see \Piwigo\Common\ValueObject\TagId}:
 * that method is a raw BatchWriter passthrough (bypasses the ORM
 * entirely, unlike the read-side {@see ImageTagLink}), so a real caller
 * already unwraps `TagId::$value` at construction time.
 */
final readonly class ImageTagPair
{
    public function __construct(
        public int $imageId,
        public int $tagId,
    ) {}

    /**
     * @return array{image_id: int, tag_id: int}
     */
    public function toArray(): array
    {
        return [
            'image_id' => $this->imageId,
            'tag_id' => $this->tagId,
        ];
    }
}
