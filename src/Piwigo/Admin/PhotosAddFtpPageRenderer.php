<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Latte\Runtime\Html;
use Piwigo\Admin\Projection\PhotosAddFtpView;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\Lang;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;

/**
 * Ported from admin/photos_add_ftp.php (the "ftp" tab of the "photos_add"
 * page slug, dispatched by PhotosAddSubController).
 */
final class PhotosAddFtpPageRenderer
{
    public function render(Lang $lang, CurrentTemplate $currentTemplate, Renderer $renderer): AdminPageResult
    {
        $ftp_help_content_raw = $lang->load(
            'help/photos_add_ftp.html',
            '',
            [
                'return' => true,
            ]
        );

        $adminContent = $renderer->render(new PhotosAddFtpView(
            ftpHelpContent: new Html(is_string($ftp_help_content_raw) ? $ftp_help_content_raw : ''),
        ));

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $lang->t('Upload Photos'),
        );
    }
}
