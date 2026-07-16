<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\Lang;
use Piwigo\Template\Template;

/**
 * Ported from admin/photos_add_ftp.php (the "ftp" tab of the "photos_add"
 * page slug, dispatched by PhotosAddSubController).
 */
final class PhotosAddFtpPageRenderer
{
    public function render(): void
    {
        /** @var Template $template */
        global $template;

        $template->assign(
            'FTP_HELP_CONTENT',
            Lang::load(
                'help/photos_add_ftp.html',
                '',
                [
                    'return' => true,
                ]
            )
        );

        $template->assign('ADMIN_PAGE_TITLE', l10n('Upload Photos'));

        $template->assign_var_from_handle('ADMIN_CONTENT', 'photos_add');
    }
}
