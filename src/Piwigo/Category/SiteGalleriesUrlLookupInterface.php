<?php

declare(strict_types=1);

namespace Piwigo\Category;

/**
 * Seam {@see CategoryService::getFulldirs()}/{@see CategoryService::updatePath()}/
 * {@see CategoryService::getGalleriesUrlForCategory()} take as an explicit
 * per-call parameter (not constructor-injected -- CategoryService has 33
 * real construction sites, the vast majority never needing this, same
 * "only the methods that actually need it take it explicitly" reasoning
 * already established for {@see \Piwigo\Core\ActivityLoggerInterface} in
 * this same class). `sites` is owned by {@see \Piwigo\Site\SiteRepository}
 * -- this interface-seam decoupling is the intended shape here, not a
 * layer-constraint workaround: `Category` and `Site` are both
 * `L2aCoreDomain` today, and the seam still exists so `CategoryService`'s
 * 33 construction sites stay free of a dependency the vast majority of
 * them never need. Implemented by
 * `SiteRepository` itself, wired at each real call site, same
 * `Mail\MailRecipientRepositoryInterface`-style seam already established
 * in this codebase.
 */
interface SiteGalleriesUrlLookupInterface
{
    /**
     * @return array<int|string, string> id => galleries_url
     */
    public function findAllGalleriesUrls(): array;

    /**
     * $categoryId's own site's galleries_url, via the site_id FK join, or
     * null when the category has no site (or doesn't exist).
     */
    public function findGalleriesUrlForCategory(int $categoryId): ?string;
}
