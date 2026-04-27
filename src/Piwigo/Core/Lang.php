<?php

declare(strict_types=1);

namespace Piwigo\Core;

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
        self::$data = $GLOBALS['lang'];
        $GLOBALS['lang'] = &self::$data;
    }

    public static function t(string $key, mixed ...$args): string
    {
        if (!isset(self::$data[$key])) {
            if (!empty($key) && Config::debugL10n()) {
                trigger_error('[l10n] language key "' . $key . '" not defined', E_USER_WARNING);
            }
            $val = $key;
        } else {
            $val = self::$data[$key];
        }
        if (!empty($args)) {
            $val = vsprintf($val, $args);
        }
        return $val;
    }

    public static function has(string $key): bool
    {
        return isset(self::$data[$key]);
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
