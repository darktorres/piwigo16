<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * One row of `permalinks.latte`'s `$deleted_permalinks` list, built by
 * {@see \Piwigo\Controller\Admin\PermalinksSubController::handle()} from
 * a real {@see \Piwigo\Permalink\Projection\OldPermalink} plus 2 spliced
 * view-only fields (`name`, `uDelete`).
 */
final readonly class DeletedPermalinkRow
{
    public function __construct(
        public int $catId,
        public string $permalink,
        public ?string $dateDeleted,
        public ?string $lastHit,
        public int $hit,
        public string $name,
        public string $uDelete,
    ) {}
}
