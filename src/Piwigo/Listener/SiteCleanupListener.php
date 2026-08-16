<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Override;
use Piwigo\Category\Event\DeleteSite;
use Piwigo\Site\SiteRepository;

/**
 * Extracted from `Bootstrap\RequestBootstrap::finalize()`'s own
 * `DeleteSite` registration. `Category\CategoryService::deleteSite()`
 * dispatches this instead of reaching into `Site\SiteRepository` directly
 * (a real Deptrac boundary: `Category` is L2aCoreDomain, `Site` is
 * L2bExtendedDomain). `RequestBootstrap` constructs this listener
 * explicitly (not via container autowiring) so `$siteRepository` reuses
 * the request's own shared `Connection` instead of building a separate
 * one.
 */
final readonly class SiteCleanupListener implements ListenerInterface
{
    public function __construct(
        private SiteRepository $siteRepository,
    ) {}

    #[Override]
    public function subscribedEvents(): array
    {
        return [
            DeleteSite::class => $this->onDeleteSite(...),
        ];
    }

    public function onDeleteSite(DeleteSite $event): void
    {
        $this->siteRepository->delete($event->siteId);
    }
}
