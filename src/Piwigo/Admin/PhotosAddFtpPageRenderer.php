<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Projection\PhotosAddFtpPageContext;
use Piwigo\Core\Lang;
use Piwigo\Template\CurrentTemplate;

/**
 * Ported from admin/photos_add_ftp.php (the "ftp" tab of the "photos_add"
 * page slug, dispatched by PhotosAddSubController).
 */
final class PhotosAddFtpPageRenderer
{
    public function render(Lang $lang, CurrentTemplate $currentTemplate): void
    {
        $template = $currentTemplate->get();

        $ftp_help_content_raw = $lang->load(
            'help/photos_add_ftp.html',
            '',
            [
                'return' => true,
            ]
        );

        $template->assignContext(new PhotosAddFtpPageContext(
            ftpHelpContent: is_string($ftp_help_content_raw) ? $ftp_help_content_raw : '',
            adminPageTitle: $lang->t('Upload Photos'),
        ));

        $template->assign_var_from_handle('ADMIN_CONTENT', 'photos_add');
    }
}
