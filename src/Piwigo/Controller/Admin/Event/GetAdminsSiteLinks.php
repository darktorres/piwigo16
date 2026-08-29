<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Event;

use Piwigo\Contribution\SiteLink;

/**
 * Typed event for the legacy `get_admins_site_links` filter. No handler
 * is registered for it anywhere today. `$siteId` is `string`, not the
 * reference's `int` -- its one real dispatch site
 * (`SiteManagerSubController.php`) passes the site row's string id
 * (`(string) $row->id`), not the real int `$id_int` it also has in scope.
 * Mutable on `$pluginLinks`; `$siteId`/`$isRemote` stay context. The
 * payload is a `list<SiteLink>` rather than the reference's raw
 * `U_HREF`/`U_HINT`/`U_CAPTION` arrays -- v17.0 breaks every PEM
 * extension deliberately (docs/PLAN.md's §1.4), and this event has
 * no handler registered anywhere in the tree to break.
 */
final class GetAdminsSiteLinks
{
    /**
     * @param list<SiteLink> $pluginLinks
     */
    public function __construct(
        public array $pluginLinks,
        public readonly string $siteId,
        public readonly bool $isRemote,
    ) {}
}
