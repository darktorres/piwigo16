<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/photo.php (page slug "photo") -- a nested tab-dispatcher,
 * same shape as plugins.php/themes.php/album.php: its own body does
 * `include admin/picture_modify.php|picture_coi.php|picture_formats.php`
 * based on `?tab=`, unchanged here. picture_coi/picture_formats are
 * additionally directly linked/reachable as their own top-level `?page=`
 * slugs (already migrated in the Config batch); picture_modify.php is only
 * ever reached via this nested dispatch in real usage (no direct link to
 * `?page=picture_modify` anywhere in templates or tests, despite its own
 * defensive "can also be accessed without photo.php as proxy" fallback), so
 * it stays nested-only -- same precedent as cat_modify.php/cat_perm.php/
 * element_set_ranks.php under album.php (Albums batch).
 */
final class PhotoSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/photo.php';
    }
}
