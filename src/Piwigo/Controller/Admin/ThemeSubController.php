<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Config\Config;
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
 */
final class ThemeSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        $theme_raw = $_GET['theme'] ?? null;
        if (! is_string($theme_raw) || $theme_raw === '') {
            die('Invalid theme URL');
        }
        $theme = $theme_raw;

        $fs_themes = new ExtensionScanner()
            ->scan(ExtensionType::Theme);
        if (! in_array($theme, array_keys($fs_themes), true)) {
            die('Invalid theme');
        }

        $filename = Config::themesPath() . $theme . '/admin/admin.inc.php';
        if (is_file($filename)) {
            include_once $filename;
        } else {
            die('Missing file ' . $filename);
        }
    }
}
