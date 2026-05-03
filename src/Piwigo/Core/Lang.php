<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Config\Config;

/**
 * Typed facade over the $lang global array.
 *
 * Wave A: self::$data IS the same array as $GLOBALS['lang'] (via reference after
 * attachGlobals()). The free function l10n() continues to read from $lang, so
 * old call-sites and new call-sites through Lang::t() see identical translation
 * strings without double-loading.
 */
final class Lang
{
    /** @var array<string,string> */
    private static array $data = [];

    /**
     * Called by Kernel::boot() after load_language() has populated $GLOBALS['lang'].
     */
    public static function attachGlobals(): void
    {
        $raw = $GLOBALS['lang'] ?? [];
        if (is_array($raw)) {
            /** @var array<string,string> $typed */
            $typed = $raw;
            self::$data = $typed;
        }
        $GLOBALS['lang'] = &self::$data;
    }

    public static function t(string $key, mixed ...$args): string
    {
        // Before attachGlobals() self::$data is empty; fall back to the raw global
        // so l10n() calls during common.inc.php bootstrap still return translations.
        // After attachGlobals() $GLOBALS['lang'] IS self::$data via reference, so
        // the result is identical either way.
        $rawGlobal = $GLOBALS['lang'] ?? [];
        /** @var array<string,string> $langGlobal */
        $langGlobal = is_array($rawGlobal) ? $rawGlobal : [];
        $src = self::$data !== [] ? self::$data : $langGlobal;

        if (!isset($src[$key])) {
            if (!empty($key) && Config::debugL10n()) {
                trigger_error('[l10n] language key "' . $key . '" not defined', E_USER_WARNING);
            }
            $val = $key;
        } else {
            $val = $src[$key];
        }
        if (!empty($args)) {
            $scalarArgs = array_map(static fn (mixed $a): string => is_scalar($a) || $a === null ? (string) $a : '', $args);
            return vsprintf($val, $scalarArgs);
        }
        return $val;
    }

    public static function has(string $key): bool
    {
        return isset(self::$data[$key]);
    }

    /** Day name by day-of-week index (0 = Sunday). */
    public static function day(int $dow): string
    {
        $raw = $GLOBALS['lang'] ?? [];
        $lang = is_array($raw) ? $raw : [];
        $days = $lang['day'] ?? [];
        if (!is_array($days) || !isset($days[$dow])) {
            return '';
        }
        $val = $days[$dow];
        return is_scalar($val) ? (string) $val : '';
    }

    /** Month name by month number (1 = January). */
    public static function month(int $m): string
    {
        $raw = $GLOBALS['lang'] ?? [];
        $lang = is_array($raw) ? $raw : [];
        $months = $lang['month'] ?? [];
        if (!is_array($months) || !isset($months[$m])) {
            return '';
        }
        $val = $months[$m];
        return is_scalar($val) ? (string) $val : '';
    }

    // ---- Test helpers ----------------------------------------------------

    /** @param array<string,string> $data */
    public static function loadArray(array $data): void
    {
        self::$data = $data;
    }

    public static function reset(): void
    {
        self::$data = [];
    }
}
