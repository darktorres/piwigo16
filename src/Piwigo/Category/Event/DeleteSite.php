<?php

declare(strict_types=1);

namespace Piwigo\Category\Event;

/**
 * Dispatched by {@see \Piwigo\Category\CategoryService::deleteSite()} once
 * the site's own categories are already gone, so {@see \Piwigo\Site\SiteRepository}
 * can delete its own `sites` row without `Category` reaching into `Site`
 * directly -- see that method's own docblock for why this event
 * indirection is the intended shape here, not a layer-constraint
 * workaround. Listener registered in {@see \Piwigo\Bootstrap\RequestBootstrap},
 * same mechanism as every other default event handler.
 */
final readonly class DeleteSite
{
    public function __construct(
        public int $siteId,
    ) {}
}
