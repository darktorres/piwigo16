<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::massInsertGroupAccess()}'s
 * own `group_access` insert row.
 */
final readonly class GroupCategoryPair
{
    public function __construct(
        public int $groupId,
        public int $catId,
    ) {}

    /**
     * @return array{group_id: int, cat_id: int}
     */
    public function toArray(): array
    {
        return [
            'group_id' => $this->groupId,
            'cat_id' => $this->catId,
        ];
    }
}
