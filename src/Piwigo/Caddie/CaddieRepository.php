<?php

declare(strict_types=1);

namespace Piwigo\Caddie;

use Doctrine\DBAL\ParameterType;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the caddie domain: `caddie` (a per-user
 * "shopping basket" of image ids, added from fill_caddie()/ws_caddie_add()).
 */
final class CaddieRepository extends AbstractRepository
{
    /**
     * Adds the given elements to a user's caddie. An element already
     * present is silently skipped (INSERT IGNORE against the table's own
     * (user_id, element_id) primary key) -- behaviorally the same as the
     * originals' own "diff against what's already there, then insert only
     * the new ones" two-step, without needing the extra SELECT. Returns
     * the number of elements actually newly added.
     *
     * @param array<int, int> $elementIds
     */
    public function addElements(int $userId, array $elementIds): int
    {
        $added = 0;
        foreach ($elementIds as $elementId) {
            $added += (int) $this->conn->executeStatement(
                'INSERT IGNORE INTO ' . Tables::caddie() . ' (element_id, user_id) VALUES (?, ?)',
                [$elementId, $userId],
                [ParameterType::INTEGER, ParameterType::INTEGER],
            );
        }

        return $added;
    }
}
