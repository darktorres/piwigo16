<?php

declare(strict_types=1);

namespace Piwigo\Image;

final class DerivativeEncoding
{
    /** Formats a derivative type (one of IMG_*) into a 2-char URL token. */
    public static function derivativeToUrl(string $t): string
    {
        return substr($t, 0, 2);
    }

    /**
     * Parses a size URL token (e.g. "120" or "432x324") into a [w, h] array.
     * @return int[]
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
     * Formats a size array into a URL token.
     * @param array<int|float> $s
     */
    public static function sizeToUrl(array $s): int|string
    {
        if ($s[0] == $s[1]) {
            return (int) $s[0];
        }
        return (string) $s[0] . 'x' . (string) $s[1];
    }

    /**
     * @param array<int|float> $s1
     * @param array<int|float>|null $s2
     */
    public static function sizeEquals(array $s1, ?array $s2): bool
    {
        return $s2 !== null && ($s1[0] == $s2[0] && $s1[1] == $s2[1]);
    }

    /** Converts a char a-z into a 0..1 float. */
    public static function charToFraction(string $c): float|int
    {
        return (ord($c[0]) - ord('a')) / 25;
    }

    /** Converts a 0..1 float into a char a-z. */
    public static function fractionToChar(float|int $f): string
    {
        return chr(min(255, max(0, (int) ((float) ord('a') + round((float) $f * 25.0)))));
    }
}
