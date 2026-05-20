<?php

declare(strict_types=1);

namespace Piwigo\Comment;

use Doctrine\DBAL\ParameterType;

/** One SQL WHERE clause fragment used in the comment getList filter chain. */
final readonly class SqlFilterClause
{
    public function __construct(
        public string $sql,
        public mixed $param,
        public ParameterType $type,
        public SqlFilterKind $kind,
    ) {
    }
}
