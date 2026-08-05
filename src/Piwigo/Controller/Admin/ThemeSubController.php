<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/theme.php's own body (page slug "theme"), folded directly
 * into this controller (P23 sub-batch 6i-2) -- validates the requested
 * theme id against ExtensionScanner's own scan (already migrated off the
 * legacy themes.class.php god-class in a prior pass), then dynamically
 * includes that theme's own admin/admin.inc.php. No other real caller of
 * admin/theme.php exists (confirmed via grep) -- admin.php's own routing
 * already gates this page behind check_status(AccessLevel::Administrator)
 * before dispatch, so the shell's own (redundant) copy of that check is
 * dropped here, same precedent as every prior sub-batch's shell fold.
 *
 * P26/SEC-40: $_GET['theme'] parsing/validation extracted into
 * Request\ThemeIdRequest -- see that class's own docblock.
 */
final class ThemeSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly UrlServiceInterface $urlService,
        private readonly \Piwigo\Core\HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        $theme = Request\ThemeIdRequest::fromGlobals()->themeId;

        $fs_themes = new ExtensionScanner()
            ->scan(ExtensionType::Theme, $this->urlService, $this->lang);
        if (! in_array($theme, array_keys($fs_themes), true)) {
            $this->htmlRenderer
                ->fatalError('Invalid theme');
        }

        $filename = $this->currentConfig->themesPath() . $theme . '/admin/admin.inc.php';
        if (is_file($filename)) {
            include_once $filename;
        } else {
            $this->htmlRenderer
                ->fatalError('Missing file ' . $filename);
        }
    }
}
