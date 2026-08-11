<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\CatOptionsPageRenderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/cat_options.php (page slug "cat_options") -- a flat
 * page, pure delegate. The page's own bulk comments/visible/status/
 * representative toggling now calls
 * Piwigo\Admin\Category\CategoryAdminService::setCategoryOption()
 * (consolidating 8 switch-case branches into one parameterized method).
 */
final readonly class CatOptionsSubController implements AdminSubControllerInterface
{
    public function __construct(
        private CatOptionsPageRenderer $catOptionsPageRenderer,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        $this->catOptionsPageRenderer
            ->render();
    }
}
