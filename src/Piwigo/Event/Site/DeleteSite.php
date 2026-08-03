<?php

declare(strict_types=1);

namespace Piwigo\Event\Site;

/**
 * Dispatched by {@see \Piwigo\Category\CategoryService::deleteSite()} once
 * the site's own categories are already gone, so {@see \Piwigo\Site\SiteRepository}
 * (`Site`, `L2bExtendedDomain`) can delete its own `sites` row without
 * `Category` (`L2aCoreDomain`) ever depending on it directly -- `deptrac.yaml`
 * only allows downward dependencies, and reaching from Category into Site
 * would be the reverse. Listener registered in
 * {@see \Piwigo\Bootstrap\RequestBootstrap}, same mechanism as every other
 * default event handler.
 */
final readonly class DeleteSite
{
    public function __construct(
        public int $siteId,
    ) {}
}
