<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\HistoryPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Core\UrlServiceInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/history.php (page slug "history") -- a flat page, pure
 * delegate. Its own data access was already almost entirely factored into
 * Piwigo\History\HistoryService/HistoryRepository during P18 (this file
 * only ever called them through the free-function bridge in
 * admin/include/functions_history.inc.php, e.g. get_history() -- that
 * bridge file was deleted in P23 sub-batch 8b-2, its real callers
 * retargeted directly to HistoryService) -- no new
 * remaining gap was this page's own username-lookup query, fixed by
 * adding Piwigo\Users\UserRepository::findUsernameById() (also closes a
 * real, if narrow, correctness gap: the old raw query hardcoded 'id'/
 * 'username' literally instead of reading \Piwigo\Config\CurrentConfig::userFields(), unlike
 * every sibling admin page that reads that same user table).
 */
final class HistorySubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new HistoryPageRenderer()
            ->render(\Piwigo\Core\Lang::current(), $this->accessControl, 'history', $this->urlService, $this->coreTabs, $this->currentTemplate);
    }
}
