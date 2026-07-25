<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/themes_standard_pages.php (page slug
 * "themes_standard_pages") -- a flat page, pure delegate onto the shared
 * Piwigo\Admin\ThemesStandardPagesPageRenderer (P23 sub-batch 6i-2), which
 * this page slug's own render() call shares with ThemesSubController's
 * "standard_pages" tab -- see that class's own docblock for the CSRF/
 * conf_update_param() investigation notes, carried forward unchanged.
 */
final class ThemesStandardPagesSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        \Piwigo\Bootstrap\AdminAccessor::themesStandardPagesPageRenderer()
            ->render();
    }
}
