<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * Pure, stateless encoding helpers used by `SizingParams` (derivative
 * filename tokens), `ImageRect` (center-of-interest crop math), and
 * `PictureCoiPageRenderer` (the admin COI editor form).
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
     * Formats a size into an identifier usable in filename.
     */
    public static function sizeToUrl(Dimensions $size): int|string
    {
        if ($size->width === $size->height) {
            return (int) $size->width;
        }

        return $size->width . 'x' . $size->height;
    }

    /**
     * Parses a size identifier out of a derivative filename token --
     * the exact inverse of sizeToUrl() ('NNN' or 'WWWxHHH').
     */
    public static function urlToSize(string $s): Dimensions
    {
        $pos = strpos($s, 'x');
        if ($pos === false) {
            return new Dimensions((int) $s, (int) $s);
        }

        return new Dimensions((int) substr($s, 0, $pos), (int) substr($s, $pos + 1));
    }

    public static function sizeEquals(Dimensions $size1, Dimensions $size2): bool
    {
        return $size1->width === $size2->width && $size1->height === $size2->height;
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
        $codepoint = ord('a') + (int) round($fraction * 25.0);
        if ($codepoint < 0) {
            $codepoint = 0;
        } elseif ($codepoint > 255) {
            $codepoint = 255;
        }

        return chr($codepoint);
    }
}
