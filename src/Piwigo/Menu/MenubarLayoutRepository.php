<?php

declare(strict_types=1);

namespace Piwigo\Menu;

use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persists a menubar's block-position layout (`config.value`, param
 * `blk_<menu id>`) -- the write half only. The read half stays
 * `global $conf` access in BlockManager::prepare_display() and
 * admin/menubar.php (matches this project's established "use global
 * $conf for admin-configurable settings" convention: $conf is already
 * populated from this same row at boot, a live DB read would only ever
 * re-read the same value within a request).
 */
final class MenubarLayoutRepository extends AbstractRepository
{
    /**
     * @param array<int|string, int> $positions block id => signed position
     *   (negative = hidden), matching admin/menubar.php's own shape.
     */
    public function saveLayout(string $menuId, array $positions): void
    {
        // A real upsert, not a plain UPDATE -- `blk_<menuId>` has no seed
        // row in install/config.sql (gap-closure Stage 1a-bis item 1
        // dropped blk_menubar's own empty-string seed row, relying on its
        // ?array PHP default instead), so a fresh install has no existing
        // row for a plain UPDATE to match; a real, adversarially-found
        // regression confirmed this silently no-ops the very first save on
        // any fresh install.
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::config() . ' (param, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['blk_' . $menuId, json_encode($positions)]
        );

        // This write bypasses ConfigService::confUpdateParam() entirely (no
        // DI dependency here, matching this class's own "write half only"
        // scope), so its own cache-clearing never fires -- without this,
        // ConfigService::allRowsFromCacheOrDb() would keep serving the
        // pre-save layout to every request until some unrelated config
        // write happened to clear the pool.
        \Piwigo\Cache\CachePools::config()->clear();
    }
}
