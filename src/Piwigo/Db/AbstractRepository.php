<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;

/**
 * Base class for domain repositories introduced from P17 onward.
 *
 * Holds only the DBAL connection -- unlike the reference implementation's
 * equivalent, there is no $tablePrefix/table() helper here. This repo
 * already has Piwigo\Db\Tables (P16), a typed accessor per table that
 * handles the prefix itself; subclasses call Tables::sessions(),
 * Tables::sites(), etc. directly instead of building table names from a
 * suffix string.
 */
abstract class AbstractRepository
{
    public function __construct(
        protected readonly Connection $conn,
    ) {}

    /**
     * Shared batch-insert/update helper (Legacy Coupling Retirement:
     * DI+DBAL migration Phase 1a), replacing `MysqliDb::singleInsert()`/
     * `massInserts()`/`singleUpdate()`/`massUpdates()` -- used by ~20
     * call sites across many domains, not any one repository's own
     * concern, so it's constructed here once for every subclass to reuse
     * rather than duplicated.
     */
    protected function batchWriter(): BatchWriter
    {
        return new BatchWriter($this->conn);
    }
}
