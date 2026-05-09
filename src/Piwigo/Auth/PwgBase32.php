<?php

declare(strict_types=1);

namespace Piwigo\Auth;

/**
 * Encode in Base32 based on RFC 4648.
 * Requires 20% more space than base64
 * Great for case-insensitive filesystems like Windows and URL's  (except for = char which can be excluded using the pad option for urls)
 *
 * @author Bryan Ruiz
 * @url https://www.php.net/manual/en/function.base-convert.php#102232
 **/
final class PwgBase32
{
    /** @var array<int,string> */
    private static array $map = [
      'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', //  7
      'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', // 15
      'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', // 23
      'Y', 'Z', '2', '3', '4', '5', '6', '7', // 31
      '=',  // padding char
    ];

    /** @var array<int|string,string> */
    private static array $flippedMap = [
      'A' => '0', 'B' => '1', 'C' => '2', 'D' => '3', 'E' => '4', 'F' => '5', 'G' => '6', 'H' => '7',
      'I' => '8', 'J' => '9', 'K' => '10', 'L' => '11', 'M' => '12', 'N' => '13', 'O' => '14', 'P' => '15',
      'Q' => '16', 'R' => '17', 'S' => '18', 'T' => '19', 'U' => '20', 'V' => '21', 'W' => '22', 'X' => '23',
      'Y' => '24', 'Z' => '25', '2' => '26', '3' => '27', '4' => '28', '5' => '29', '6' => '30', '7' => '31',
    ];

    /**
     *    Use padding false when encoding for urls
     *
     * @return string base32 encoded
     **/
    public static function encode(string $input, bool $padding = true): string
    {
        if (empty($input)) {
            return '';
        }
        $input = str_split($input);
        $binaryString = '';
        for ($i = 0; $i < count($input); $i++) {
            $binaryString .= str_pad(base_convert((string)ord($input[$i]), 10, 2), 8, '0', STR_PAD_LEFT);
        }
        $fiveBitBinaryArray = str_split($binaryString, 5);
        $base32 = '';
        $i = 0;
        while ($i < count($fiveBitBinaryArray)) {
            $base32 .= self::$map[(int) base_convert(str_pad($fiveBitBinaryArray[$i], 5, '0'), 2, 10)];
            $i++;
        }
        if ($padding && ($x = strlen($binaryString) % 40) != 0) {
            if ($x == 8) {
                $base32 .= str_repeat(self::$map[32], 6);
            } elseif ($x == 16) {
                $base32 .= str_repeat(self::$map[32], 4);
            } elseif ($x == 24) {
                $base32 .= str_repeat(self::$map[32], 3);
            } elseif ($x == 32) {
                $base32 .= self::$map[32];
            }
        }
        return $base32;
    }

    /** @return string|false */
    public static function decode(string $input): string|false
    {
        if (empty($input)) {
            return false;
        }
        $paddingCharCount = substr_count($input, self::$map[32]);
        $allowedValues = [6, 4, 3, 1, 0];
        if (!in_array($paddingCharCount, $allowedValues)) {
            return false;
        }
        for ($i = 0; $i < 4; $i++) {
            if (
                $paddingCharCount == $allowedValues[$i] &&
                substr($input, - ($allowedValues[$i])) != str_repeat(self::$map[32], $allowedValues[$i])
            ) {
                return false;
            }
        }
        $input = str_replace('=', '', $input);
        $input = str_split($input);
        $binaryString = '';
        for ($i = 0; $i < count($input); $i = $i + 8) {
            $x = '';
            if (!in_array($input[$i], self::$map)) {
                return false;
            }
            for ($j = 0; $j < 8; $j++) {
                $ch = $input[$i + $j] ?? null;
                $mapped = ($ch !== null && isset(self::$flippedMap[$ch])) ? self::$flippedMap[$ch] : 0;
                $x .= str_pad(base_convert((string) $mapped, 10, 2), 5, '0', STR_PAD_LEFT);
            }
            $eightBits = str_split($x, 8);
            for ($z = 0; $z < count($eightBits); $z++) {
                $binaryString .= (($y = chr(min(255, max(0, (int)base_convert($eightBits[$z], 2, 10))))) || ord($y[0]) == 48) ? $y : '';
            }
        }
        return $binaryString;
    }
}
