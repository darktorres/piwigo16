<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findMoveDetailsByIds()}'s own
 * row shape -- feeds a category-move operation's own name/uppercats
 * bookkeeping.
 */
final readonly class CategoryMoveDetailRow
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $dir,
        public string $uppercats,
    ) {}
}
