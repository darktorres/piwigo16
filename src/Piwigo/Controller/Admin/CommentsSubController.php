<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\CommentsPageRenderer;
use Piwigo\Admin\CoreTabs;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/comments.php (page slug "comments") -- pure page/template
 * glue, no data access of its own (comment moderation itself is a client-side
 * ws.php/AJAX flow against the existing CommentService).
 */
final class CommentsSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly CurrentTemplate $currentTemplate,
        private readonly EventDispatcher $eventDispatcher,
        private readonly CurrentConfig $currentConfig,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        new CommentsPageRenderer()
            ->render($this->lang, $this->accessControl, $this->urlService, $this->coreTabs, $this->currentTemplate, $this->eventDispatcher, $this->currentConfig);
    }
}
