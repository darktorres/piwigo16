<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Auth\AccessControl;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Template\Template;

/**
 * Ported from admin/comments.php (page slug "comments") -- pure page/
 * template glue, no data access of its own (comment moderation itself is a
 * client-side ws.php/AJAX flow against the existing CommentService, P18).
 */
final class CommentsPageRenderer
{
    public function render(Lang $lang, AccessControl $accessControl, UrlServiceInterface $urlService, CoreTabs $coreTabs, \Piwigo\Template\CurrentTemplate $currentTemplate, \Piwigo\PluginConfig\EventDispatcher $eventDispatcher): void
    {
        $template = $currentTemplate->get();

        $accessControl->checkStatus(AccessLevel::Administrator);

        $template->set_filenames([
            'comments' => 'comments.tpl',
        ]);

        $template->assign(
            [
                'F_ACTION' => $urlService->getRootUrl() . 'admin.php?page=comments',
                'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
            ]
        );

        // Legacy Coupling Retirement Phase 8, 8g: real, previously-unfixed
        // bug -- nothing had ever called CoreTabs::setContext() with
        // myBaseUrl for this page (same class of gap as
        // ConfigurationSubController's own $conf_link fix), so this page's
        // own tab strip has always rendered a broken relative href.
        $coreTabs->setContext(new CoreTabsContext(myBaseUrl: $urlService->getRootUrl() . 'admin.php?page='));
        $tabsheet = new Tabsheet();
        $tabsheet->set_id('comments');
        $tabsheet->select('', $eventDispatcher);
        $tabsheet->assign($currentTemplate);

        $template->assign('ADMIN_PAGE_TITLE', $lang->t('User comments'));

        $template->assign_var_from_handle('ADMIN_CONTENT', 'comments');
    }
}
