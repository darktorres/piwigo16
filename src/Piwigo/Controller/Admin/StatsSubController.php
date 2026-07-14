<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\StatsPageRenderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/stats.php (page slug "stats") -- a sibling top-level page
 * to "history" sharing the same tabsheet group (history_tabsheet() selects
 * on the real page slug, not a `?tab=` param). Its history_summary chart
 * queries are single-purpose view-shaping for this one page (date-bucketing
 * into hour/day/month/year series), the same "page/template glue stays
 * inline" precedent as HistorySubController -- no new service was
 * warranted.
 */
final class StatsSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new StatsPageRenderer()
            ->render();
    }
}
