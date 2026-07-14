<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\CatListPageRenderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/cat_list.php (page slug "cat_list") -- a flat page, pure
 * delegate to Piwigo\Admin\CatListPageRenderer (P23 batch 6f). The page's
 * own create/delete write logic already calls
 * Piwigo\Admin\Category\CategoryAdminService::createVirtualCategory()
 * (typed CreateCategoryResult replacing the free function's loosely-typed
 * array return) -- getCategoriesRefDate() is not used by this page (an
 * earlier batch found this file's own copy was genuinely dead code --
 * defined but never called from within cat_list.php itself -- and deleted
 * it rather than routing it through the service).
 */
final class CatListSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new CatListPageRenderer()
            ->render();
    }
}
