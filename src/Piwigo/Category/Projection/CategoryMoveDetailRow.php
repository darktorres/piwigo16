<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findMoveDetailsByIds()}'s own
 * row shape -- {@see \Piwigo\Ws\Categories::move()}'s real (and only)
 * consumer.
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
