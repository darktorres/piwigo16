<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the `themes` table -- no ORM entity exists for it
 * (no real caller ever needed one beyond the id/name listing below), so
 * this stays plain DBAL via {@see AbstractRepository}, same shape as
 * Notification\NotificationByMailRepository. Lives alongside ThemeCatalog
 * in `Piwigo\Core` (L1Infrastructure) rather than a new `Piwigo\Theme`
 * namespace -- deptrac.yaml's own L1Infrastructure collector is a fixed
 * namespace enumeration, and ThemeCatalog itself already established this
 * namespace as the correct L1 home for "no natural existing class home,
 * but a real L2aCoreDomain caller (Users\UserService) exists" theme
 * concerns (see ThemeCatalog's own docblock).
 */
final class ThemeRepository extends AbstractRepository
{
    /**
     * id/name for every installed theme row, ordered by name --
     * ThemeCatalog::getPwgThemes()'s own catalog listing.
     *
     * @return list<array{id: string, name: string}>
     */
    public function findAllIdsAndNames(): array
    {
        $themesTable = Tables::themes();
        $rows = $this->conn->fetchAllAssociative(<<<SQL
            SELECT
                id,
                name
            FROM {$themesTable}
            ORDER BY name ASC
            SQL);

        $themes = [];
        foreach ($rows as $row) {
            if (! is_string($row['id']) || ! is_string($row['name'])) {
                continue;
            }

            $themes[] = [
                'id' => $row['id'],
                'name' => $row['name'],
            ];
        }

        return $themes;
    }
}
