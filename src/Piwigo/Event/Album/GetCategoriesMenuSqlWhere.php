<?php

declare(strict_types=1);

namespace Piwigo\Event\Album;

/**
 * Typed event for legacy `get_categories_menu_sql_where` (dispatch).
 *
 * Dispatched from: src/Piwigo/Category/CategoryService.php
 */
final readonly class GetCategoriesMenuSqlWhere
{
    public function __construct(
        public string $where,
        public bool $userExpand,
        public bool $filterEnabled,
    ) {
    }
}
