<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\CommentsPageRenderer;
use Piwigo\Admin\CoreTabs;
use Piwigo\Auth\AccessControl;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/comments.php (page slug "comments") -- pure page/template
 * glue, no data access of its own (comment moderation itself is a client-side
 * ws.php/AJAX flow against the existing CommentService, P18).
 */
final class CommentsSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new CommentsPageRenderer()
            ->render($this->lang, $this->accessControl, $this->urlService, $this->coreTabs, $this->currentTemplate);
    }
}
