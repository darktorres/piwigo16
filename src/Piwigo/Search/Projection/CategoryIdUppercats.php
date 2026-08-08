<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

use Piwigo\Common\ValueObject\CategoryId;

/**
 * {@see \Piwigo\Search\SearchRepository::findCategoryIdsAndUppercats()}'s
 * own row shape -- {@see \Piwigo\Search\SearchFilterRenderer}'s real (and
 * only) consumer, its `cat`/album-lookup autocomplete block.
 */
final readonly class CategoryIdUppercats
{
    public function __construct(
        public CategoryId $id,
        public string $uppercats,
    ) {}
}
