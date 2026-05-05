<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;

final class QueryHelper
{
    public function __construct(
        private readonly Connection $conn,
    ) {}

    /** @return array<mixed> */
    public function simpleHashFromQuery(string $query, string $keyname, string $valuename): array
    {
        return array_column($this->conn->executeQuery($query)->fetchAllAssociative(), $valuename, $keyname);
    }

    /** @return array<mixed> */
    public function hashFromQuery(string $query, string $keyname): array
    {
        return array_column($this->conn->executeQuery($query)->fetchAllAssociative(), null, $keyname);
    }

    /** @return array<mixed> */
    public function arrayFromQuery(string $query, string|false $fieldname = false): array
    {
        if ($fieldname === false) {
            return $this->conn->executeQuery($query)->fetchAllAssociative();
        }
        return array_column($this->conn->executeQuery($query)->fetchAllAssociative(), $fieldname);
    }
}
