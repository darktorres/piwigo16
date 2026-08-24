<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\HistoryPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
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
final readonly class HistorySubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private UrlServiceInterface $urlService,
        private CoreTabs $coreTabs,
        private CurrentTemplate $currentTemplate,
        private CurrentConfig $currentConfig,
        private EventDispatcher $eventDispatcher,
        private InputValidator $inputValidator,
        private EntityManagerInterface $entityManager,
        private Renderer $renderer,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): AdminPageResult
    {
        return new HistoryPageRenderer()
            ->render($this->lang, $this->accessControl, 'history', $this->urlService, $this->coreTabs, $this->currentTemplate, $this->currentConfig, $this->eventDispatcher, $this->inputValidator, $this->entityManager, $this->renderer);
    }
}
