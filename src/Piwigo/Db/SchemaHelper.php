<?php

declare(strict_types=1);

namespace Piwigo\Db;

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
        foreach (DbConnection::get()->executeQuery('DESC ' . $table)->fetchAllAssociative() as $row) {
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
