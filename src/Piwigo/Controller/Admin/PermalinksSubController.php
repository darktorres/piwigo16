<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/permalinks.php (page slug "permalinks") -- a flat page,
 * pure delegate. Its per-category writes already go through
 * Piwigo\Permalink\PermalinkService::deleteCatPermalink()/setCatPermalink()
 * (P17, via the admin/include/functions_permalinks.php free-function
 * bridge). This batch extracted the remaining raw
 * `DELETE FROM old_permalinks WHERE permalink = '...' LIMIT 1` (old-
 * permalink-history deletion) into
 * PermalinkRepository::deleteOldPermalinkByValue() + a new
 * PermalinkService::deleteOldPermalinkByValue() wrapper, called directly
 * (no free-function wrapper added -- delete_cat_permalink()/
 * set_cat_permalink() are only ever called from this same file, so a
 * third one-caller free function isn't worth the indirection).
 */
final class PermalinksSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/permalinks.php';
    }
}
