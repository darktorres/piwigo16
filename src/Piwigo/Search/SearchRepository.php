<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Piwigo\Db\AbstractRepository;

/**
 * Persistence layer for the search domain.
 */
final class SearchRepository extends AbstractRepository
{
    /**
     * Count saved searches with the given UUID.
     * Used to generate a unique UUID (returns 0 when the candidate is free).
     */
    public function countByUuid(string $uuid): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('search'))
            ->where('search_uuid = :uuid')
            ->setParameter('uuid', $uuid)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }
}
