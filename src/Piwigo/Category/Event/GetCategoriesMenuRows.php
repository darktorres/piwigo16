<?php

declare(strict_types=1);

namespace Piwigo\Category\Event;

/**
 * Typed event for the legacy `get_categories_menu_sql_where` filter. The
 * legacy hook filtered a raw SQL WHERE-clause string (`string $where,
 * bool $userExpand, bool $filterEnabled`); `CategoryService::
 * getCategoriesMenu()` no longer builds one at all -- it reads from
 * `CategoryTreeCache`'s cached, permission-filtered row set and applies an
 * equivalent PHP-side filter (`CategoryService::filterMenuRows()`)
 * instead, so there is no SQL string left to hand a plugin. Preserves the
 * real capability (customize which categories appear in the category
 * menu) against the mechanism that actually exists today: mutable on
 * `$rows`, the already-filtered row set; `$userExpand`/`$filterEnabled`
 * stay context, matching the legacy vars minus the now-nonexistent SQL
 * string.
 */
final class GetCategoriesMenuRows
{
    /**
     * @param array<int, array{cat_id: int, id_uppercat: ?int, global_rank: ?string, rank: ?int, date_last: ?string, nb_images: int, user_id: int, nb_categories: int, count_categories: int, count_images: int, max_date_last: ?string, name: string, permalink: ?string, id: int}> $rows
     */
    public function __construct(
        public array $rows,
        public readonly bool $userExpand,
        public readonly bool $filterEnabled,
    ) {}
}
