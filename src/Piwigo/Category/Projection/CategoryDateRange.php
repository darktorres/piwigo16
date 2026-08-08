<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findDateRangeByCategory()}'s
 * own row shape -- {@see \Piwigo\Category\CategoryCatsRenderer}'s own
 * "from/to" photo-date-range display, its real (and only) consumer.
 */
final readonly class CategoryDateRange
{
    public function __construct(
        public ?string $from,
        public ?string $to,
    ) {}
}
