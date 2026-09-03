<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Latte\Runtime\Html;

/**
 * One row of `permalinks.latte`'s `$deleted_permalinks` list, built by
 * {@see \Piwigo\Controller\Admin\PermalinksSubController::handle()} from
 * a real {@see \Piwigo\Permalink\Projection\OldPermalink} plus 2 spliced
 * view-only fields (`name`, `uDelete`).
 *
 * $name is Html, not a plain string (P59): same
 * getCatDisplayNameCache()-sourced trusted markup as
 * {@see PermalinkListRow::$name}.
 */
final readonly class DeletedPermalinkRow
{
    public function __construct(
        public int $catId,
        public string $permalink,
        public ?string $dateDeleted,
        public ?string $lastHit,
        public int $hit,
        public Html $name,
        public string $uDelete,
    ) {}
}
