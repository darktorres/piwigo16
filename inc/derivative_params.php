<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

class derivative_params
{
    /**
     * Formats a size name into a 2 chars identifier usable in filename.
     *
     * @param string $t one of IMG_*
     */
    public static function derivative_to_url(
        string $t
    ): string {
        return substr($t, 0, 2);
    }

    /**
     * Formats a size array into a identifier usable in filename.
     *
     * @param array<int> $s
     */
    public static function size_to_url(
        array $s
    ): int|string {
        if ($s[0] == $s[1]) {
            return $s[0];
        }

        return $s[0] . 'x' . $s[1];
    }

    /**
     * @param array<int> $s1
     * @param array<int> $s2
     */
    public static function size_equals(
        array $s1,
        array $s2
    ): bool {
        return $s1[0] == $s2[0] &&
               $s1[1] == $s2[1];
    }

    /**
     * Converts a char a-z into a float.
     */
    public static function char_to_fraction(
        string $c
    ): float|int {
        return (ord($c) - ord('a')) / 25;
    }

    /**
     * Converts a float into a char a-z.
     */
    public static function fraction_to_char(
        float|string $f
    ): string {
        return chr(ord('a') + (int) round($f * 25));
    }
}
