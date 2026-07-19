<?php

declare(strict_types=1);

namespace Piwigo\Section;

use Piwigo\Db\AbstractRepository;

/**
 * Persistence layer for SectionPopulator/SectionInitializer's own
 * hand-built, per-section-branch SQL (categories/favorites/recent_pics/
 * most_visited/best_rated/list/flat-subcat item-id queries, the favorites
 * DELETE) -- same "generic, fully parameterized executors instead of one
 * method per query shape" rationale as Search\SearchRepository, since these
 * WHERE/ORDER-BY fragments are assembled dynamically by the caller across
 * many branches, not a fixed shape a typed method could usefully wrap.
 */
final class SectionRepository extends AbstractRepository
{
    /**
     * Same shape as {@see \Piwigo\Db\MysqliDb::query2Array()} called with
     * only a value column name (`query2Array($sql, null, $column)`) --
     * every value cast to string|null the same way
     * {@see \Piwigo\Db\MysqliDb::fetchAssoc()} always did, so this is a
     * behavior-preserving 1:1 API swap for SectionPopulator's own
     * single-column item-id queries.
     *
     * @return list<string|null>
     */
    public function queryColumn(string $sql): array
    {
        return array_map(
            static fn (mixed $value): ?string => is_scalar($value) ? (string) $value : null,
            $this->conn->executeQuery($sql)
                ->fetchFirstColumn()
        );
    }

    public function executeStatement(string $sql): void
    {
        $this->conn->executeStatement($sql);
    }

    /**
     * Real driver-escaping, without the surrounding quotes {@see
     * \Doctrine\DBAL\Connection::quote()} adds -- SectionInitializer's own
     * use of this (a $_GET-key character-escape) was never actually SQL, it
     * (mis)used mysqli_real_escape_string() as a general string sanitizer
     * for a URL path token; ported as-is rather than changed to something
     * SQL-appropriate, since altering that escaping behavior is out of this
     * migration's "same result" scope.
     */
    public function escapeToken(string $value): string
    {
        return substr($this->conn->quote($value), 1, -1);
    }
}
