<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\Lang;

/**
 * Ported from admin/photos_add_applications.php (the "applications" tab of
 * the "photos_add" page slug, dispatched by PhotosAddSubController).
 */
final class PhotosAddApplicationsPageRenderer
{
    public function render(): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $template->assign('ADMIN_PAGE_TITLE', Lang::t('Upload Photos'));

        $template->assign_var_from_handle('ADMIN_CONTENT', 'photos_add');
    }
}
