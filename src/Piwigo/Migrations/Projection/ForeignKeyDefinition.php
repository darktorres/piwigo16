<?php

declare(strict_types=1);

namespace Piwigo\Migrations\Projection;

/**
 * {@see \Piwigo\Migrations\Version20260804122303::foreignKeys()}'s own row
 * shape -- one FK constraint this baseline schema declares. `needsIndex` is
 * Postgres/SQLite-only (see that method's own docblock): whether the
 * referencing column needs its own `CREATE INDEX`, since (unlike InnoDB)
 * neither platform indexes FK columns automatically.
 */
final readonly class ForeignKeyDefinition
{
    public function __construct(
        public string $table,
        public string $constraintName,
        public string $column,
        public string $refTable,
        public string $refColumn,
        public string $onDelete,
        public bool $needsIndex,
    ) {}
}
