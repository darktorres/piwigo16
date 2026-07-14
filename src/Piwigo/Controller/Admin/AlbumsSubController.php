<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\AlbumsPageRenderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/albums.php (page slug "albums") -- a flat page (no tab
 * dispatch), so this sub-controller is a pure delegate to
 * Piwigo\Admin\AlbumsPageRenderer (P23 batch 6f). The page's own
 * auto-order write logic already calls
 * Piwigo\Admin\Category\CategoryAdminService::getCategoriesRefDate()
 * (an earlier batch's dedup of a real byte-for-byte duplicate that used to
 * also live in admin/cat_list.php).
 */
final class AlbumsSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new AlbumsPageRenderer()
            ->render();
    }
}
