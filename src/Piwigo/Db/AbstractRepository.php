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
}
