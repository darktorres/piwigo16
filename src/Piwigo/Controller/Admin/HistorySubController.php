<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\HistoryPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/history.php (page slug "history") -- a flat page, pure
 * delegate. Its own data access was already almost entirely factored into
 * Piwigo\History\HistoryService/HistoryRepository (the free-function
 * bridge this file used to call them through,
 * admin/include/functions_history.inc.php's get_history(), no longer
 * exists; real callers are retargeted directly to HistoryService). The
 * one remaining gap was this page's own username-lookup query, fixed by
 * adding Piwigo\Users\UserRepository::findUsernameById() (also closes a
 * real, if narrow, correctness gap: the old raw query hardcoded 'id'/
 * 'username' literally instead of reading \Piwigo\Config\CurrentConfig::userFields(), unlike
 * every sibling admin page that reads that same user table).
 */
final class HistorySubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly CurrentTemplate $currentTemplate,
        private readonly CurrentConfig $currentConfig,
        private readonly EventDispatcher $eventDispatcher,
        private readonly InputValidator $inputValidator,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        new HistoryPageRenderer()
            ->render($this->lang, $this->accessControl, 'history', $this->urlService, $this->coreTabs, $this->currentTemplate, $this->currentConfig, $this->eventDispatcher, $this->inputValidator);
    }
}
