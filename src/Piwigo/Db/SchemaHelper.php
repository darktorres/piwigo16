<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Piwigo\Core\Kernel;

final class SchemaHelper
{
    /**
     * Returns the possible values of an enum column.
     *
     * @return string[]
     */
    public static function getEnums(string $table, string $field): array
    {
        $options = [];
        foreach (Kernel::service(Connection::class)->executeQuery('DESC ' . $table)->fetchAllAssociative() as $row) {
            if ($row['Field'] === $field) {
                $raw = explode(',', substr(is_string($row['Type'] ?? null) ? $row['Type'] : '', 5, -1));
                foreach ($raw as $option) {
                    $options[] = str_replace("'", '', $option);
                }
            }
        }
        return $options;
    }
}
