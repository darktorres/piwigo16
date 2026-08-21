<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\CommentsPageRenderer;
use Piwigo\Admin\CoreTabs;
use Piwigo\Auth\AccessControl;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/comments.php (page slug "comments") -- pure page/template
 * glue, no data access of its own (comment moderation itself is a client-side
 * `/api/v1/comments` flow against the existing CommentService).
 */
final readonly class CommentsSubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private UrlServiceInterface $urlService,
        private CoreTabs $coreTabs,
        private CurrentTemplate $currentTemplate,
        private EventDispatcher $eventDispatcher,
        private CsrfService $csrfService,
        private Renderer $renderer,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): AdminPageResult
    {
        return new CommentsPageRenderer()
            ->render($this->lang, $this->accessControl, $this->urlService, $this->coreTabs, $this->currentTemplate, $this->eventDispatcher, $this->csrfService, $this->renderer);
    }
}
