<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/help.php (page slug "help") -- pure page/template glue, no
 * data access (loads a static help/*.html language file via the existing
 * load_language() bridge). admin/popuphelp.php is a distinct, standalone
 * root-style entry point (self-bootstraps PHPWG_ROOT_PATH/common.inc.php,
 * never dispatched through admin.php) -- out of scope here, same "root
 * entry points are P23 scope" precedent as index.php/install.php.
 */
final class HelpSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/help.php';
    }
}
