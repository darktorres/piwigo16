<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/themes.php (page slug "themes") -- a tab dispatcher
 * (installed/new/update/standard_pages) that stays a pure delegate. The
 * "standard_pages" tab includes admin/themes_standard_pages.php directly
 * (unchanged, already migrated as its own page slug in the Config batch's
 * ThemesStandardPagesSubController -- both routes reach the same file).
 * The other 2 leaf files this dispatches to (themes_installed.php/
 * themes_new.php, plus the shared updates_ext.php for "update") were
 * migrated off the themes.class.php god-class onto PemCatalog/
 * ExtensionScanner/ExtensionLifecycle/ExtensionRepository (this batch's
 * real scope).
 */
final class ThemesSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/themes.php';
    }
}
