<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\StatsPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\History\HistoryService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;
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
final readonly class StatsSubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private UrlServiceInterface $urlService,
        private ConfigService $configService,
        private CoreTabs $coreTabs,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private HistoryService $historyService,
        private CurrentConfig $currentConfig,
        private EventDispatcher $eventDispatcher,
        private Renderer $renderer,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        new StatsPageRenderer()
            ->render($this->lang, $this->accessControl, 'stats', $this->urlService, $this->configService, $this->coreTabs, $this->currentUser, $this->currentTemplate, $this->currentConfig, $this->historyService, $this->eventDispatcher, $this->renderer);
    }
}
