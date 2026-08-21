<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Projection\PhotosAddApplicationsView;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\Lang;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;

/**
 * Ported from admin/photos_add_applications.php (the "applications" tab of
 * the "photos_add" page slug, dispatched by PhotosAddSubController).
 */
final class PhotosAddApplicationsPageRenderer
{
    public function render(Lang $lang, CurrentTemplate $currentTemplate, Renderer $renderer): AdminPageResult
    {
        $adminContent = $renderer->render(new PhotosAddApplicationsView());

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $lang->t('Upload Photos'),
        );
    }
}
