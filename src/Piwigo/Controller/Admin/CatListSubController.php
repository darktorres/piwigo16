<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\CatListPageRenderer;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Page slug "cat_list" -- a flat page, pure delegate to
 * Piwigo\Admin\CatListPageRenderer. Create/delete write logic calls
 * Piwigo\Admin\Category\CategoryAdminService::createVirtualCategory(),
 * which returns a typed CreateCategoryResult.
 */
final readonly class CatListSubController implements AdminSubControllerInterface
{
    public function __construct(
        private CatListPageRenderer $catListPageRenderer,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): AdminPageResult
    {
        return $this->catListPageRenderer
            ->render();
    }
}
