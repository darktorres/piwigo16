<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/configuration.php (page slug "configuration") -- a large
 * tabbed page (main/watermark/sizes/comments/default/display/search), pure
 * delegate. Its own `?section=` tab dispatch stays inline (matches
 * plugins.php/themes.php's own tab-dispatch shape, confirmed too deeply
 * tied to this single file's local $page['section'] switch to be worth
 * splitting into 7 separate sub-controllers).
 *
 * Real write paths verified during this batch: the "watermark"/"sizes"
 * tabs delegate to admin/include/configuration_{watermark,sizes}_process.
 * inc.php, both already writing through typed abstractions
 * (ImageStdParams::save()/set_and_save(), UploadService::
 * saveUploadFormConfig()) with no raw SQL. The "default" tab's
 * build_user()/save_profile_from_post() calls are task #343's own
 * already-closed scope (see ProfileSubController). The one remaining
 * generic-config-row UPDATE loop already double-quotes its value
 * (str_replace("\'", "''", ...)) before splicing it into SQL -- safe,
 * just stylistically raw; left as-is rather than routed through
 * ConfigService (Doctrine ORM + container-injected, unlike every other
 * P21 service so far, which are plain-DBAL and self-construct inline --
 * introducing that plumbing for an already-safe write path isn't
 * proportionate to this batch).
 *
 * This batch also fixed a real, verified bug in this file: $lang['day']
 * is never actually defined by any language/*\/common.lang.php (confirmed
 * across every locale) nor any runtime code, so the direct (unguarded)
 * read on the "main" tab threw "Undefined array key" -- fixed with the
 * same ?? guard already used for this exact key elsewhere (admin/intro.php,
 * format_date_legacy() in include/functions.inc.php).
 */
final class ConfigurationSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/configuration.php';
    }
}
