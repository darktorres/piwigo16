<?php

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
     * @return string
     */
    public static function derivative_to_url($t)
    {
        return substr($t, 0, 2);
    }

    /**
     * Formats a size array into a identifier usable in filename.
     *
     * @param int[] $s
     * @return string
     */
    public static function size_to_url($s)
    {
        if ($s[0] == $s[1]) {
            return $s[0];
        }
        return $s[0] . 'x' . $s[1];
    }

    /**
     * @param int[] $s1
     * @param int[] $s2
     * @return bool
     */
    public static function size_equals($s1, $s2)
    {
        return $s1[0] == $s2[0] && $s1[1] == $s2[1];
    }

    /**
     * Converts a char a-z into a float.
     *
     * @param string $c
     * @return float
     */
    public static function char_to_fraction($c)
    {
        return (ord($c) - ord('a')) / 25;
    }

    /**
     * Converts a float into a char a-z.
     *
     * @param float $f
     * @return string
     */
    public static function fraction_to_char($f)
    {
        return chr(ord('a') + round($f * 25));
    }
}
