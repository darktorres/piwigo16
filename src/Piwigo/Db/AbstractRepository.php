<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;

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
}
