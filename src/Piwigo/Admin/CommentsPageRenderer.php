<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Projection\CommentsPageContext;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;

/**
 * Ported from admin/comments.php (page slug "comments") -- pure page/
 * template glue, no data access of its own (comment moderation itself is a
 * client-side ws.php/AJAX flow against the existing CommentService).
 */
final class CommentsPageRenderer
{
    public function render(Lang $lang, AccessControl $accessControl, UrlServiceInterface $urlService, CoreTabs $coreTabs, CurrentTemplate $currentTemplate, EventDispatcher $eventDispatcher, CurrentConfig $currentConfig): void
    {
        $template = $currentTemplate->get();

        $accessControl->checkStatus(AccessLevel::Administrator);

        $template->set_filenames([
            'comments' => 'comments.tpl',
        ]);

        // CoreTabs::setContext() must be called with myBaseUrl here so this
        // page's tab strip renders correct admin.php?page=... hrefs instead
        // of broken relative ones.
        $coreTabs->setContext(new CoreTabsContext(myBaseUrl: $urlService->getRootUrl() . 'admin.php?page='));
        $tabsheet = new Tabsheet();
        $tabsheet->setId('comments');
        $tabsheet->select('', $eventDispatcher);
        $tabsheet->assign($currentTemplate);

        $template->assignContext(new CommentsPageContext(
            formAction: $urlService->getRootUrl() . 'admin.php?page=comments',
            pwgToken: new CsrfService($currentConfig)
                ->getToken(),
            adminPageTitle: $lang->t('User comments'),
        ));

        $template->assign_var_from_handle('ADMIN_CONTENT', 'comments');
    }
}
