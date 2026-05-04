<?php

declare(strict_types=1);

namespace Piwigo\Db;

final class QueryHelper
{
    /**
     * Executes $query and returns the result as a PHP array.
     *
     * @phpstan-return (
     *   $key_name is null
     *     ? ($value_name is null ? list<array<string, float|int|string|null>> : list<float|int|string|null>)
     *     : ($value_name is null ? array<string, array<string, float|int|string|null>> : array<string, float|int|string|null>)
     * )
     */
    public static function fetch(string $query, ?string $key_name = null, ?string $value_name = null): array
    {
        $rows = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
        $data = [];

        $sv = static fn (mixed $v): float|int|string|null => (is_float($v) || is_int($v) || is_string($v)) ? $v : null;
        /** @param array<string,mixed> $row @return array<string,float|int|string|null> */
        $sr = static fn (array $row): array => array_map($sv, $row);

        if (isset($key_name)) {
            if (isset($value_name)) {
                foreach ($rows as $row) {
                    $data[is_scalar($row[$key_name]) ? (string) $row[$key_name] : ''] = $sv($row[$value_name]);
                }
            } else {
                foreach ($rows as $row) {
                    $data[is_scalar($row[$key_name]) ? (string) $row[$key_name] : ''] = $sr($row);
                }
            }
        } else {
            if (isset($value_name)) {
                foreach ($rows as $row) {
                    $data[] = $sv($row[$value_name]);
                }
            } else {
                $data = array_map($sr, $rows);
            }
        }

        return $data;
    }
}
