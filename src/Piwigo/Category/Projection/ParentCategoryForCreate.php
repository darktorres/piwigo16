<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findParentCategoryForCreate()}'s
 * own row shape -- {@see \Piwigo\Category\CategoryService}'s own "create a
 * new category under this parent" flow, its real (and only) consumer.
 */
final readonly class ParentCategoryForCreate
{
    public function __construct(
        public int $id,
        public string $uppercats,
        public string $globalRank,
        public bool $visible,
        public string $status,
    ) {}
}
