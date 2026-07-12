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
        $this->conn->executeStatement(
            'UPDATE ' . Tables::config() . ' SET value = ? WHERE param = ?',
            [serialize($positions), 'blk_' . $menuId]
        );
    }
}
