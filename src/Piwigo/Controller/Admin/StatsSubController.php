<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\StatsPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigService;
use Piwigo\Core\UrlServiceInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/stats.php (page slug "stats") -- a sibling top-level page
 * to "history" sharing the same tabsheet group (built inline in
 * StatsPageRenderer, selecting on the real page slug, not a `?tab=`
 * param). Its history_summary chart
 * queries are single-purpose view-shaping for this one page (date-bucketing
 * into hour/day/month/year series), the same "page/template glue stays
 * inline" precedent as HistorySubController -- no new service was
 * warranted.
 */
final class StatsSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
        private readonly CoreTabs $coreTabs,
        private readonly \Piwigo\Users\CurrentUser $currentUser,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly \Piwigo\History\HistoryService $historyService,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new StatsPageRenderer()
            ->render($this->accessControl, 'stats', $this->urlService, $this->configService, $this->coreTabs, $this->currentUser, $this->currentTemplate, $this->historyService);
    }
}
