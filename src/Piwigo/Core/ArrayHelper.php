<?php

declare(strict_types=1);

namespace Piwigo\Core;

final class ArrayHelper
{
    /**
     * Apply json_decode() on a value only if it is a string
     *
     * @param array<int|string, mixed>|string $value
     * @return array<int|string, mixed>
     */
    public static function safeJsonDecode(array|string $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            // json_decode(..., true) returns null/scalar for malformed input or
            // a JSON scalar; this method's contract is always an array.
            return is_array($decoded) ? $decoded : [];
        }
        return $value;
    }

    /**
     * Prepends and appends strings at each value of the given array.
     *
     * @param array<int|string, mixed> $array
     * @return array<int|string, string>
     */
    public static function prependAppendArrayItems(array $array, string $prependStr, string $appendStr): array
    {
        array_walk($array, function (mixed &$value, int|string $key) use ($prependStr, $appendStr): void {
            $value = $prependStr . (is_scalar($value) ? (string) $value : '') . $appendStr;
        });
        return $array;
    }
}
