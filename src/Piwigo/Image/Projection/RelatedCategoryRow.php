<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\ImageRepository::findRelatedCategoriesForImage()}'s
 * own row shape -- {@see \Piwigo\Ws\Images\GetInfoHandler}'s own "related
 * categories" block, its only real consumer.
 *
 * `toArray()` exists for that consumer: each row is mutated in place
 * afterward (a `url`/`page_url` key added, `commentable` unset, `name`
 * re-rendered through a plugin hook) before being handed onward as a
 * loose row, including into {@see \Piwigo\Core\UrlServiceInterface::
 * makeIndexUrl()}'s own deliberately-generic `array $params` -- same
 * "typed until it hits a generic sink" boundary as every other
 * Projection in this codebase that feeds `makeIndexUrl()`/`SrcImage`.
 */
final readonly class RelatedCategoryRow
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $permalink,
        public string $uppercats,
        public ?string $globalRank,
        public bool $commentable,
    ) {}

    /**
     * @return array{id: int, name: string, permalink: ?string, uppercats: string, global_rank: ?string, commentable: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'permalink' => $this->permalink,
            'uppercats' => $this->uppercats,
            'global_rank' => $this->globalRank,
            'commentable' => $this->commentable,
        ];
    }
}
