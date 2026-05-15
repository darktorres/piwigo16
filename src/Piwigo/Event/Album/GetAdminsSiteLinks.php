<?php

declare(strict_types=1);

namespace Piwigo\Event\Album;

/**
 * Typed event for legacy `get_admins_site_links` (dispatch).
 *
 * Dispatched from: src/Piwigo/Controller/Admin/MaintenanceController.php
 */
final readonly class GetAdminsSiteLinks
{
    /**
     * @param array<mixed> $pluginLinks
     */
    public function __construct(
        public array $pluginLinks,
        public int $siteId,
        public bool $isRemote,
    ) {
    }

    /**
     * @param array<mixed> $pluginLinks
     */
    public function withPluginLinks(array $pluginLinks): self
    {
        return new self($pluginLinks, $this->siteId, $this->isRemote);
    }
}
