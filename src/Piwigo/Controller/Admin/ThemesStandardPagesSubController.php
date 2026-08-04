<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\ThemesStandardPagesPageRenderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/themes_standard_pages.php (page slug
 * "themes_standard_pages") -- a flat page, pure delegate onto the shared
 * Piwigo\Admin\ThemesStandardPagesPageRenderer (P23 sub-batch 6i-2), which
 * this page slug's own render() call shares with ThemesSubController's
 * "standard_pages" tab -- both routes reach the same renderer, which
 * CSRF-gates its `confUpdateParam()` calls via
 * `CsrfService::checkOrFail()` before any write.
 */
final class ThemesStandardPagesSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly ThemesStandardPagesPageRenderer $themesStandardPagesPageRenderer,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        $this->themesStandardPagesPageRenderer
            ->render();
    }
}
