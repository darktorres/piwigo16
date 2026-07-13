<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/cat_list.php (page slug "cat_list") -- a flat page, pure
 * delegate. The page's own create/delete write logic already calls
 * Piwigo\Admin\Category\CategoryAdminService::createVirtualCategory()
 * (typed CreateCategoryResult replacing the free function's loosely-typed
 * array return) and getCategoriesRefDate() (this batch's dedup target;
 * this file's own copy was found to be genuinely dead code -- defined but
 * never called from within cat_list.php itself -- and was deleted rather
 * than routed through the service).
 */
final class CatListSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/cat_list.php';
    }
}
