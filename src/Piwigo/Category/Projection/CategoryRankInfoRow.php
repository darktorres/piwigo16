<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findRankInfoByIds()}'s own
 * row shape -- {@see \Piwigo\Ws\Categories::setRank()}'s real (and
 * only) consumer.
 */
final readonly class CategoryRankInfoRow
{
    public function __construct(
        public int $id,
        public ?int $idUppercat,
        public ?int $rank,
    ) {}
}
