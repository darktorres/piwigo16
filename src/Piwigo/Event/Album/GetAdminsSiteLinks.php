<?php

declare(strict_types=1);

namespace Piwigo\Event\Album;

/**
 * Typed event for the legacy `get_admins_site_links` filter. No handler
 * is registered for it anywhere today. `$siteId` is `string`, not the
 * reference's `int` -- its one real dispatch site
 * (`SiteManagerSubController.php`) passes the site row's string id
 * (`(string) $row->id`), not the real int `$id_int` it also has in scope.
 */
final readonly class GetAdminsSiteLinks
{
    /**
     * @param array<mixed> $pluginLinks
     */
    public function __construct(
        public array $pluginLinks,
        public string $siteId,
        public bool $isRemote,
    ) {}
}
