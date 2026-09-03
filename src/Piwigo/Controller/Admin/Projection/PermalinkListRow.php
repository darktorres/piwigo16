<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Latte\Runtime\Html;

/**
 * One row of `permalinks.latte`'s `$permalinks` list, built by
 * {@see \Piwigo\Controller\Admin\PermalinksSubController::handle()}.
 * Drops the raw row's own `uppercats`/`global_rank` fields -- both are
 * used only for sorting before this row is built
 * ({@see \Piwigo\Category\CategoryService::compareByGlobalRank()}),
 * never read by `permalinks.latte` itself (confirmed: only
 * `id`/`name`/`permalink` appear in that template).
 *
 * $name is Html, not a plain string (P59): HtmlService::
 * getCatDisplayNameCache()'s own trusted, pre-formed breadcrumb HTML
 * (already htmlspecialchars()'s every real category name it interpolates).
 */
final readonly class PermalinkListRow
{
    public function __construct(
        public int $id,
        public Html $name,
        public ?string $permalink,
    ) {}
}
