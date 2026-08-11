<?php

declare(strict_types=1);

namespace Piwigo\Core;

final class CharsetHelper
{
    /**
     * converts a string from a character set to another character set
     */
    public static function convertCharset(string $str, string $sourceCharset, string $destCharset): string|false
    {
        if ($sourceCharset === $destCharset) {
            return $str;
        }
        if ($sourceCharset === 'iso-8859-1' && $destCharset === 'utf-8') {
            return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
        }
        if ($sourceCharset === 'utf-8' && $destCharset === 'iso-8859-1') {
            return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
        }
        if (function_exists('iconv')) {
            return iconv($sourceCharset, $destCharset . '//TRANSLIT', $str);
        }
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($str, $destCharset, $sourceCharset);
        }
        // Unreachable in practice: ext-iconv and ext-mbstring are both
        // hard composer.json requirements, so one of the two branches
        // above always fires. Defensive last-resort no-op, not real
        // pending work.
        return $str;
    }
}
