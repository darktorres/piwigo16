<?php

declare(strict_types=1);

namespace Piwigo\Category\Event;

/**
 * Dispatched by {@see \Piwigo\Category\CategoryService::deleteSite()} once
 * the site's own categories are already gone, so {@see \Piwigo\Site\SiteRepository}
 * can delete its own `sites` row without `Category` reaching into `Site`
 * directly -- see that method's own docblock for why this event
 * indirection still stands even though the original deptrac boundary
 * behind it (`Site` was `L2bExtendedDomain`, `Category` is
 * `L2aCoreDomain`, and `deptrac.yaml` only allows downward dependencies)
 * is gone. Listener registered in {@see \Piwigo\Bootstrap\RequestBootstrap},
 * same mechanism as every other default event handler. Co-located here
 * from `Piwigo\Event\Site\DeleteSite` (P32 Stage A5 -- see
 * `docs/events-legacy-map.md`).
 */
final readonly class DeleteSite
{
    public function __construct(
        public int $siteId,
    ) {}
}
