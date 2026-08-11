<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Projection\PhotosAddApplicationsPageContext;
use Piwigo\Core\Lang;
use Piwigo\Template\CurrentTemplate;

/**
 * Ported from admin/photos_add_applications.php (the "applications" tab of
 * the "photos_add" page slug, dispatched by PhotosAddSubController).
 */
final class PhotosAddApplicationsPageRenderer
{
    public function render(Lang $lang, CurrentTemplate $currentTemplate): void
    {
        $template = $currentTemplate->get();

        $template->assignContext(new PhotosAddApplicationsPageContext(adminPageTitle: $lang->t('Upload Photos')));

        $template->assignVarFromHandle('ADMIN_CONTENT', 'photos_add');
    }
}
