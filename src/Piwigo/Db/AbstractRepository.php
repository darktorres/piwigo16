<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Base class for all domain repositories.
 *
 * Holds the DBAL connection and the configured table prefix so every
 * subclass can call $this->table('tags') instead of repeating the prefix.
 */
abstract class AbstractRepository
{
    public function __construct(
        protected readonly Connection $conn,
        protected readonly string $tablePrefix,
    ) {
    }

    /** Return the fully-prefixed table name, e.g. 'piwigo_tags'. */
    protected function table(string $suffix): string
    {
        return $this->tablePrefix . $suffix;
    }

    /**
     * Run the given QueryBuilder (expected to SELECT a single scalar
     * column from a single row) and coerce the result to int, or 0 if
     * the query returned NULL / no row. Contains the per-call
     * MixedAssignment that `Result::fetchOne(): mixed` would otherwise
     * leak into every repo method.
     */
    protected function fetchOneInt(QueryBuilder $qb): int
    {
        $value = $qb->executeQuery()->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Run the given QueryBuilder (expected to SELECT a single scalar
     * column from a single row) and return the string value, or null
     * if the query returned NULL / no row.
     */
    protected function fetchOneString(QueryBuilder $qb): ?string
    {
        $value = $qb->executeQuery()->fetchOne();
        return is_string($value) ? $value : null;
    }
}
