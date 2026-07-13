<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/theme.php (page slug "theme") -- dynamic inclusion of an
 * active theme's own admin/admin.inc.php. Pure delegate; the file's own
 * fs_themes validity check now goes through ExtensionScanner instead of
 * the themes.class.php god-class (this batch's real scope).
 */
final class ThemeSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/theme.php';
    }
}
