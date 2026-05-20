<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Boolean ↔ string conversions used throughout the codebase.
 * Moved here from the legacy dblayer shim; the DB layer has no business
 * knowing about this conversion.
 */
final class BoolUtil
{
    /**
     * Convert any value to bool, treating the string 'false' as false.
     * Every other truthy value returns true, every falsy value returns false.
     */
    public static function fromMixed(mixed $input): bool
    {
        if (is_scalar($input) && 'false' === strtolower((string) $input)) {
            return false;
        }
        return (bool) $input;
    }

    /**
     * Return 1 or 0 using the same boolean semantics as {@see self::fromMixed()}.
     * Used when writing to TINYINT(1) boolean schema columns.
     *
     * @param bool|null|string $input
     */
    public static function toInt(bool|string|null $input): int
    {
        return self::fromMixed($input) ? 1 : 0;
    }
}
