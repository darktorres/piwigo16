<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findCategoriesForFulldirs()}'s
 * own row shape -- {@see \Piwigo\Category\CategoryService::getFulldirs()}'s
 * real (and only) consumer.
 */
final readonly class CategoryFulldirRow
{
    public function __construct(
        public int $id,
        public string $uppercats,
        public ?int $siteId,
    ) {}
}
