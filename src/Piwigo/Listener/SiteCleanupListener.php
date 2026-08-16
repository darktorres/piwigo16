<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Override;
use Piwigo\Category\Event\DeleteSite;
use Piwigo\Core\SubscriberInterface;
use Piwigo\Site\SiteRepository;

/**
 * Extracted from `Bootstrap\RequestBootstrap::finalize()`'s own
 * `DeleteSite` registration. `Category\CategoryService::deleteSite()`
 * dispatches this instead of reaching into `Site\SiteRepository` directly
 * -- see {@see \Piwigo\Category\CategoryService::deleteSite()}'s own
 * docblock for why this event indirection still stands even though the
 * deptrac boundary that originally required it is gone. `RequestBootstrap`
 * constructs this listener explicitly (not via container autowiring) so
 * `$siteRepository` reuses the request's own shared `Connection` instead
 * of building a separate one.
 */
final readonly class SiteCleanupListener implements SubscriberInterface
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
