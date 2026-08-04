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
    public function render(Lang $lang, \Piwigo\Template\CurrentTemplate $currentTemplate): void
    {
        $template = $currentTemplate->get();

        $template->assign('ADMIN_PAGE_TITLE', $lang->t('Upload Photos'));

        $template->assign_var_from_handle('ADMIN_CONTENT', 'photos_add');
    }
}
