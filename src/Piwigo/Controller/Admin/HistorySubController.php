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
 * Page slug "history" -- a flat page, pure delegate onto
 * Piwigo\History\HistoryService/HistoryRepository. Its own
 * username-lookup query goes through
 * Piwigo\Users\UserRepository::findUsernameById(), which reads
 * \Piwigo\Config\CurrentConfig::userFields() dynamically rather than
 * hardcoding `'id'`/`'username'` literally, matching every sibling admin
 * page that reads the same user table.
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
