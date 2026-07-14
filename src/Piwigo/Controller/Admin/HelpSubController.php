<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\HelpPageRenderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/help.php (page slug "help") -- pure page/template glue, no
 * data access (loads a static help/*.html language file via the existing
 * load_language() bridge). admin/popuphelp.php is a distinct, standalone
 * root-style entry point (self-bootstraps PHPWG_ROOT_PATH/common.inc.php,
 * never dispatched through admin.php) -- ported separately in this same
 * sub-batch to Piwigo\Controller\Admin\AdminPopuphelpController, its own
 * config/routes.php entry rather than a page slug here.
 */
final class HelpSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new HelpPageRenderer()
            ->render();
    }
}
