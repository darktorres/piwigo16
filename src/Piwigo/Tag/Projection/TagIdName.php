<?php

declare(strict_types=1);

namespace Piwigo\Tag\Projection;

/**
 * Shared `id`/`name` row shape for
 * {@see \Piwigo\Tag\TagRepository::findTagsForImage()} and
 * {@see \Piwigo\Tag\TagRepository::findTagsByIds()} -- both feed
 * {@see \Piwigo\Tag\TagService::buildTagList()}'s real (and only)
 * consumer.
 *
 * `toArray()` exists for that consumer's own `RenderTagName` event
 * payload, typed `array<mixed> $tag` (a legacy filter with no registered
 * handler today, but still a real, typed contract) -- not this DTO.
 */
final readonly class TagIdName
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    /**
     * @return array{id: int, name: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
