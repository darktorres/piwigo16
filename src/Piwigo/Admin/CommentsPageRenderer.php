<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\AccessLevel;
use Piwigo\Template\Template;

/**
 * Ported from admin/comments.php (page slug "comments") -- pure page/
 * template glue, no data access of its own (comment moderation itself is a
 * client-side ws.php/AJAX flow against the existing CommentService, P18).
 */
final class CommentsPageRenderer
{
    public function render(): void
    {
        /** @var Template $template */
        global $template;

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        $template->set_filenames([
            'comments' => 'comments.tpl',
        ]);

        $template->assign(
            [
                'F_ACTION' => get_root_url() . 'admin.php?page=comments',
                'PWG_TOKEN' => get_pwg_token(),
            ]
        );

        $tabsheet = new tabsheet();
        $tabsheet->set_id('comments');
        $tabsheet->select('');
        $tabsheet->assign();

        $template->assign('ADMIN_PAGE_TITLE', l10n('User comments'));

        $template->assign_var_from_handle('ADMIN_CONTENT', 'comments');
    }
}
