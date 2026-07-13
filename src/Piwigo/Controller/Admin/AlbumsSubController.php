<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/albums.php (page slug "albums") -- a flat page (no tab
 * dispatch), so this sub-controller is a pure delegate. The page's own
 * auto-order write logic already calls
 * Piwigo\Admin\Category\CategoryAdminService::getCategoriesRefDate()
 * (this batch's dedup of a real byte-for-byte duplicate that used to also
 * live in admin/cat_list.php). Tree-building/display stays legacy
 * `include` glue, same "keep page/template glue inline" split as every
 * other P21 sub-controller.
 */
final class AlbumsSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/albums.php';
    }
}
