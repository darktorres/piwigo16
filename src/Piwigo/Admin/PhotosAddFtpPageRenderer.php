<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Projection\PhotosAddFtpView;
use Piwigo\Controller\Admin\Projection\AdminContentPageContext;
use Piwigo\Core\Lang;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;

/**
 * Ported from admin/photos_add_ftp.php (the "ftp" tab of the "photos_add"
 * page slug, dispatched by PhotosAddSubController).
 */
final class PhotosAddFtpPageRenderer
{
    public function render(Lang $lang, CurrentTemplate $currentTemplate, Renderer $renderer): void
    {
        $template = $currentTemplate->get();

        $ftp_help_content_raw = $lang->load(
            'help/photos_add_ftp.html',
            '',
            [
                'return' => true,
            ]
        );

        $adminContent = $renderer->render(new PhotosAddFtpView(
            ftpHelpContent: is_string($ftp_help_content_raw) ? $ftp_help_content_raw : '',
        ));

        $template->assignContext(new AdminContentPageContext(
            adminContent: $adminContent,
            adminPageTitle: $lang->t('Upload Photos'),
        ));
    }
}
