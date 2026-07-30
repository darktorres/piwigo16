<?php

declare(strict_types=1);

namespace Piwigo\Caddie;

use Doctrine\DBAL\ArrayParameterType;
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

    /**
     * Every element_id in $userId's own caddie -- Admin\BatchManager\
     * FilterResolver's own "caddie" prefilter.
     *
     * @return list<int>
     */
    public function findElementIdsForUser(int $userId): array
    {
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->conn->fetchFirstColumn(
                'SELECT element_id FROM ' . Tables::caddie() . ' WHERE user_id = ?',
                [$userId],
                [ParameterType::INTEGER],
            )
        );
    }

    /**
     * Empties $userId's caddie then adds $elementIds -- Admin\
     * PhotosAddDirectPageRenderer's own "batch" action, unlike
     * addElements() above which only ever adds on top of what's there.
     *
     * @param list<int> $elementIds
     */
    public function replaceForUser(int $userId, array $elementIds): void
    {
        $this->conn->executeStatement(
            'DELETE FROM ' . Tables::caddie() . ' WHERE user_id = ?',
            [$userId],
            [ParameterType::INTEGER],
        );

        $inserts = [];
        foreach ($elementIds as $elementId) {
            $inserts[] = [
                'user_id' => $userId,
                'element_id' => $elementId,
            ];
        }

        if ($inserts === []) {
            return;
        }

        $this->batchWriter()
            ->massInsert(Tables::caddie(), array_keys($inserts[0]), $inserts);
    }

    /**
     * Removes only the given elements from $userId's caddie --
     * Admin\BatchManagerGlobalPageRenderer's own "remove_from_caddie"
     * action, unlike replaceForUser() above which clears everything.
     *
     * @param list<int> $elementIds
     */
    public function removeElementsForUser(int $userId, array $elementIds): void
    {
        if ($elementIds === []) {
            return;
        }

        $this->conn->executeStatement(
            'DELETE FROM ' . Tables::caddie() . ' WHERE element_id IN (?) AND user_id = ?',
            [$elementIds, $userId],
            [ArrayParameterType::INTEGER, ParameterType::INTEGER],
        );
    }
}
