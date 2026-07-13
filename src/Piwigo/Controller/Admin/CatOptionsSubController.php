<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/cat_options.php (page slug "cat_options") -- a flat
 * page, pure delegate. The page's own bulk comments/visible/status/
 * representative toggling now calls
 * Piwigo\Admin\Category\CategoryAdminService::setCategoryOption()
 * (consolidating 8 switch-case branches into one parameterized method).
 */
final class CatOptionsSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/cat_options.php';
    }
}
