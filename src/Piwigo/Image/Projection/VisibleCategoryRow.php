<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\ImageRepository::findVisibleCategoriesForImage()}'s
 * own row shape -- {@see \Piwigo\Controller\PictureController}'s own
 * "related categories" block, its only real consumer.
 *
 * `$status` is unwrapped from `CategoryEntity`'s own `CategoryStatus`
 * enum to its plain string value here, matching every other real
 * `c.status` DQL-select site in this codebase (e.g.
 * `CategoryRepository`'s own `($row['status'] ?? null) instanceof
 * CategoryStatus ? $row['status']->value : ''` idiom, repeated at 5 real
 * sites) -- the original array-shaped version of this method left it as
 * a raw `CategoryStatus` instance instead, a real inconsistency nothing
 * ever observed since no real consumer reads this field.
 *
 * `toArray()` exists for `PictureController`'s own `usort($related_categories,
 * CategoryService::compareByGlobalRank(...))` call: that comparator is a
 * generic, cross-domain `array`-typed helper shared by 8+ unrelated call
 * sites (see {@see \Piwigo\Category\Projection\CategoryIdNameUppercatsRank}'s
 * own identical rationale), so this DTO is unwrapped to a plain array
 * once, right where it arrives, before the sort and every later read.
 */
final readonly class VisibleCategoryRow
{
    public function __construct(
        public int $id,
        public string $uppercats,
        public bool $commentable,
        public bool $visible,
        public string $status,
        public ?string $globalRank,
    ) {}

    /**
     * @return array{id: int, uppercats: string, commentable: bool, visible: bool, status: string, global_rank: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uppercats' => $this->uppercats,
            'commentable' => $this->commentable,
            'visible' => $this->visible,
            'status' => $this->status,
            'global_rank' => $this->globalRank,
        ];
    }
}
