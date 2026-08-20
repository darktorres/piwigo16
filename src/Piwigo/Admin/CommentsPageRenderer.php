<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Projection\CommentsView;
use Piwigo\Auth\AccessControl;
use Piwigo\Controller\Admin\Projection\AdminContentPageContext;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;

/**
 * Ported from admin/comments.php (page slug "comments") -- pure page/
 * template glue, no data access of its own (comment moderation itself is a
 * client-side `/api/v1/comments` flow against the existing CommentService).
 */
final class CommentsPageRenderer
{
    public function render(Lang $lang, AccessControl $accessControl, UrlServiceInterface $urlService, CoreTabs $coreTabs, CurrentTemplate $currentTemplate, EventDispatcher $eventDispatcher, CsrfService $csrfService, Renderer $renderer): void
    {
        $template = $currentTemplate->get();

        $accessControl->checkStatus(AccessLevel::Administrator);

        // CoreTabs::setContext() must be called with myBaseUrl here so this
        // page's tab strip renders correct admin.php?page=... hrefs instead
        // of broken relative ones.
        $coreTabs->setContext(new CoreTabsContext(myBaseUrl: $urlService->getRootUrl() . 'admin.php?page='));
        $tabsheet = new Tabsheet();
        $tabsheet->setId('comments');
        $tabsheet->select('', $eventDispatcher);
        $tabsheet->assign($currentTemplate, $renderer);

        $adminContent = $renderer->render(new CommentsView(
            csrfToken: $csrfService
                ->getToken(),
        ));

        $template->assignContext(new AdminContentPageContext(
            adminContent: $adminContent,
            adminPageTitle: $lang->t('User comments'),
        ));
    }
}
