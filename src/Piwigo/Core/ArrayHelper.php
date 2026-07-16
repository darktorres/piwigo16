<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * P23 batch 8d: pure array/serialization helpers relocated from
 * include/functions.inc.php -- no natural existing class home, stateless.
 */
final class ArrayHelper
{
    /**
     * Apply unserialize() on a value only if it is a string
     *
     * @param array<int|string, mixed>|string $value
     * @return mixed the unserialized value, false if $value is a malformed
     *   serialized string, or $value itself unchanged if it isn't a string
     */
    public static function safeUnserialize(array|string $value): mixed
    {
        if (is_string($value)) {
            return unserialize($value);
        }
        return $value;
    }

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
        array_walk($array, function (&$value, $key) use ($prependStr, $appendStr): void {
            $value = $prependStr . (is_scalar($value) ? (string) $value : '') . $appendStr;
        });
        return $array;
    }
}
