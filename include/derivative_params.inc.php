<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * Formats a size name into a 2 chars identifier usable in filename.
 *
 * @param string $t one of IMG_*
 */
function derivative_to_url($t): string
{
    return substr($t, 0, 2);
}

/**
 * Formats a size array into a identifier usable in filename.
 *
 * @param int[] $s
 * @return string
 */
function size_to_url(array $s): int|string
{
    if ($s[0] == $s[1]) {
        return $s[0];
    }
    return $s[0] . 'x' . $s[1];
}

/**
 * @param int[] $s1
 * @param int[] $s2
 */
function size_equals(array $s1, array $s2): bool
{
    return $s1[0] == $s2[0] && $s1[1] == $s2[1];
}

/**
 * Converts a char a-z into a float.
 *
 * @param string $c
 * @return float
 */
function char_to_fraction($c): float|int
{
    return (ord($c) - ord('a')) / 25;
}

/**
 * Converts a float into a char a-z.
 */
function fraction_to_char(float $f): string
{
    // $f can come straight from user input (admin/picture_coi.php's COI
    // form fields), so clamp rather than trust it's within [0, 1]
    $codepoint = ord('a') + (int) round($f * 25);
    if ($codepoint < 0) {
        $codepoint = 0;
    } elseif ($codepoint > 255) {
        $codepoint = 255;
    }

    return chr($codepoint);
}

// The classes formerly declared here (ImageRect, SizingParams,
// DerivativeParams) now live in src/Piwigo/Image/ (P6 batch 9); this file
// stays procedural for the 5 helper functions above, which PSR-4
// autoloading can't cover.
