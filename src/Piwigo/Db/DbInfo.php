<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;

/**
 * Constructor-injected, not the reference's Kernel::service(Connection::class)
 * locator call -- matches v17's DI convention.
 */
final readonly class DbInfo
{
    public function __construct(
        private Connection $conn,
    ) {}

    public function version(): string
    {
        $v = $this->conn->executeQuery(<<<SQL
            SELECT VERSION()
            SQL)
            ->fetchOne();
        return is_string($v) ? $v : '';
    }

    /**
     * Parses a column's live ENUM definition
     * (`enum('blue','green','black')` -> `['blue', 'green', 'black']`) --
     * matches the original `MysqliDb::getEnums()`'s own `DESC` +
     * string-parse approach; no cross-driver-portable DBAL equivalent
     * exists for reading a live ENUM definition. Shared here (not
     * inlined per-caller) since it's called on different table/field
     * pairs from multiple domains (Ws/Admin).
     *
     * @return list<string>
     */
    public function getEnums(string $table, string $field): array
    {
        $rows = $this->conn->executeQuery(<<<SQL
            DESC {$table}
            SQL)->fetchAllAssociative();

        foreach ($rows as $row) {
            if (($row['Field'] ?? null) === $field) {
                $type = is_string($row['Type'] ?? null) ? $row['Type'] : '';
                $options = explode(',', substr($type, 5, -1));

                return array_map(static fn (string $option): string => str_replace('\'', '', $option), $options);
            }
        }

        return [];
    }

    /**
     * A cheap "did this table change" fingerprint (its own max
     * `lastmodified` timestamp plus row count) -- Admin\AdminUiHelper's
     * own client-side cache-busting key, called on different tables from
     * multiple unrelated domains (categories/groups/images/tags/
     * user_infos), same "no single owning domain" shape as
     * {@see getEnums()} above.
     */
    public function getTableFingerprint(string $table): string
    {
        $value = $this->conn->fetchOne(<<<SQL
            SELECT CONCAT(
                UNIX_TIMESTAMP(MAX(lastmodified)),
                "_",
                COUNT(*)
              )
            FROM `{$table}`
            SQL);

        return is_string($value) ? $value : '';
    }
}
