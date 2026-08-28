<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * One row of `permalinks.latte`'s `$permalinks` list, built by
 * {@see \Piwigo\Controller\Admin\PermalinksSubController::handle()}.
 * Drops the raw row's own `uppercats`/`global_rank` fields -- both are
 * used only for sorting before this row is built
 * ({@see \Piwigo\Category\CategoryService::compareByGlobalRank()}),
 * never read by `permalinks.latte` itself (confirmed: only
 * `id`/`name`/`permalink` appear in that template).
 */
final readonly class PermalinkListRow
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $permalink,
    ) {}
}
