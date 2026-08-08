<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findStatusByIds()}'s own row
 * shape -- {@see \Piwigo\Category\CategoryService}'s own permission-
 * consistency pass, its real (and only) consumer.
 */
final readonly class CategoryIdStatus
{
    public function __construct(
        public int $id,
        public string $status,
    ) {}
}
