<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Typed facade over the $lang global array.
 *
 * `self::$data` IS the same array as `$GLOBALS['lang']` (a reference,
 * established by `attachGlobals()`, matching `PageState`'s own bridge
 * pattern) -- the free function `l10n()` keeps reading `$lang` directly, so
 * old call sites and new call sites through `Lang::t()` see identical
 * translation strings without double-loading or divergence.
 *
 * `t()` delegates to `Piwigo\Lang\Translator` (gettext-backed, P16) rather
 * than reading `self::$data` directly -- `Translator::translate()` itself
 * falls back to the `$lang` array (via the same bridge) for keys with no
 * gettext entry, so both PHP-array-loaded and PO-loaded strings resolve
 * correctly through one call.
 */
final class Lang
{
    /**
     * $lang['day']/$lang['month'] are the two legacy exceptions to an
     * otherwise string-valued array -- a nested list<string> keyed by
     * day-of-week/month-of-year index (see Piwigo\Lang\Translator::
     * mirrorToGlobal(), which populates them from the piwigo_day_N/
     * piwigo_month_N PO entries). day()/month() below read them back out.
     *
     * @var array<string, string|array<int, string>>
     */
    private static array $data = [];

    /**
     * Called by CommonBootstrap::run() after include/common.inc.php's
     * load_language() calls have populated $GLOBALS['lang'] -- HTTP-path
     * only, mirroring PageState::attachGlobals()'s own placement and
     * reasoning (no $lang concept on the CLI path).
     */
    public static function attachGlobals(): void
    {
        $raw = $GLOBALS['lang'] ?? [];
        if (is_array($raw)) {
            self::$data = self::filterLangValues($raw);
        }
        $GLOBALS['lang'] = &self::$data;
    }

    public static function t(string $key, mixed ...$args): string
    {
        return \Piwigo\Lang\Translator::get()->translate($key, ...$args);
    }

    public static function has(string $key): bool
    {
        return isset(self::$data[$key]);
    }

    /**
     * Day name by day-of-week index (0 = Sunday).
     */
    public static function day(int $dayOfWeek): string
    {
        return self::fromLangArray('day', $dayOfWeek);
    }

    /**
     * Month name by month number (1 = January).
     */
    public static function month(int $month): string
    {
        return self::fromLangArray('month', $month);
    }

    private static function fromLangArray(string $key, int $index): string
    {
        $raw = $GLOBALS['lang'] ?? [];
        $lang = is_array($raw) ? $raw : [];
        $group = $lang[$key] ?? null;
        if (! is_array($group) || ! isset($group[$index])) {
            return '';
        }
        $val = $group[$index];

        return is_scalar($val) ? (string) $val : '';
    }

    /**
     * Like a plain string map, except 'day'/'month' (list<string>, see
     * $data's own docblock) are kept instead of being dropped -- an earlier
     * version filtered on `is_string($v)` alone, which silently discarded
     * both keys on every request and left Lang::day()/month() (and any
     * legacy $lang['day']/$lang['month'] reader, e.g. admin/configuration.
     * php, admin/intro.php's activity chart, format_date_legacy()) always
     * returning ''/empty.
     *
     * @param array<mixed, mixed> $value
     * @return array<string, string|array<int, string>>
     */
    private static function filterLangValues(array $value): array
    {
        $result = [];
        foreach ($value as $k => $v) {
            if (! is_string($k)) {
                continue;
            }
            if (is_string($v)) {
                $result[$k] = $v;
            } elseif (is_array($v)) {
                $strings = [];
                foreach ($v as $i => $s) {
                    if (is_int($i) && is_string($s)) {
                        $strings[$i] = $s;
                    }
                }
                if ($strings !== []) {
                    $result[$k] = $strings;
                }
            }
        }

        return $result;
    }

    // ---- Test helpers ----------------------------------------------------

    /**
     * @param array<string, string> $data
     */
    public static function loadArray(array $data): void
    {
        self::$data = $data;
    }

    public static function reset(): void
    {
        self::$data = [];
    }
}
