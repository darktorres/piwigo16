<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * A raw table/column pair -- {@see
 * \Piwigo\Category\CategoryOrphanTarget::tableAndColumn()}'s own fixed
 * 2-value result.
 */
final readonly class TableColumnTarget
{
    public function __construct(
        public string $table,
        public string $column,
    ) {}
}
