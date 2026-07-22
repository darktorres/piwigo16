<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * P23 batch 8c: ported from `include/derivative_params.inc.php`'s 5 free
 * functions -- pure, stateless encoding helpers used by `SizingParams`
 * (derivative filename tokens), `ImageRect` (center-of-interest crop
 * math), and `PictureCoiPageRenderer` (the admin COI editor form).
 */
final class DerivativeUrlCodec
{
    /**
     * Formats a size name into a 2 chars identifier usable in filename.
     *
     * @param string $type one of IMG_*
     */
    public static function derivativeToUrl(string $type): string
    {
        return substr($type, 0, 2);
    }

    /**
     * Formats a size array into an identifier usable in filename.
     *
     * @param int[] $size
     */
    public static function sizeToUrl(array $size): int|string
    {
        if ($size[0] === $size[1]) {
            return $size[0];
        }

        return $size[0] . 'x' . $size[1];
    }

    /**
     * Parses a size identifier out of a derivative filename token --
     * the exact inverse of sizeToUrl() ('NNN' or 'WWWxHHH').
     *
     * P23 batch 8f (i.php): relocated from i.php's url_to_size() free
     * function, unchanged logic.
     *
     * @return array{0: int, 1: int}
     */
    public static function urlToSize(string $s): array
    {
        $pos = strpos($s, 'x');
        if ($pos === false) {
            return [(int) $s, (int) $s];
        }

        return [(int) substr($s, 0, $pos), (int) substr($s, $pos + 1)];
    }

    /**
     * @param int[] $size1
     * @param int[] $size2
     */
    public static function sizeEquals(array $size1, array $size2): bool
    {
        return $size1[0] === $size2[0] && $size1[1] === $size2[1];
    }

    /**
     * Converts a char a-z into a float.
     */
    public static function charToFraction(string $char): float|int
    {
        return (ord($char[0]) - ord('a')) / 25;
    }

    /**
     * Converts a float into a char a-z.
     */
    public static function fractionToChar(float $fraction): string
    {
        // $fraction can come straight from user input (admin/picture_coi.php's
        // COI form fields), so clamp rather than trust it's within [0, 1]
        $codepoint = ord('a') + (int) round($fraction * 25);
        if ($codepoint < 0) {
            $codepoint = 0;
        } elseif ($codepoint > 255) {
            $codepoint = 255;
        }

        return chr($codepoint);
    }
}
