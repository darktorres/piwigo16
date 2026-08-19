<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Projection\PhotosAddApplicationsView;
use Piwigo\Controller\Admin\Projection\AdminContentPageContext;
use Piwigo\Core\Lang;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;

/**
 * Ported from admin/photos_add_applications.php (the "applications" tab of
 * the "photos_add" page slug, dispatched by PhotosAddSubController).
 */
final class PhotosAddApplicationsPageRenderer
{
    public function render(Lang $lang, CurrentTemplate $currentTemplate, Renderer $renderer): void
    {
        $template = $currentTemplate->get();

        $adminContent = $renderer->render(new PhotosAddApplicationsView());

        $template->assignContext(new AdminContentPageContext(
            adminContent: $adminContent,
            adminPageTitle: $lang->t('Upload Photos'),
        ));
    }
}
