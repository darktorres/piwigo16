<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Lang\Translator;

/**
 * Typed facade for translation strings and locale data (day/month names).
 *
 * Translation strings live in self::$data (populated by Translator::mirrorToGlobal
 * via Lang::setString(), or snapshotted at boot by attachGlobals()). Day and month
 * names live in self::$days / self::$months (populated by Translator::mirrorToGlobal
 * via Lang::setDays() / Lang::setMonths()).
 *
 * No $GLOBALS bridge remains after attachGlobals() runs.
 */
final class Lang
{
    /** @var array<string,string> */
    private static array $data = [];
    /** @var array<int,string> Day names keyed 0 (Sunday) – 6 (Saturday). */
    private static array $days = [];
    /** @var array<int,string> Month names keyed 1–12. */
    private static array $months = [];

    /**
     * Locale metadata for the currently-loaded language: `code`, `name`, `parent`,
     * `direction`, etc. Populated by LanguageStack::mergeInfo() / setInfo() and
     * read by Template + AdminService::getPiwigoNews().
     *
     * @var array<string,mixed>
     */
    private static array $langInfo = [];

    /**
     * Called by Kernel::boot(). Snapshots any PHP-file-loaded lang data from
     * $GLOBALS['lang'] into the typed static properties, then clears the global.
     */
    public static function attachGlobals(): void
    {
        $raw = $GLOBALS['lang'] ?? [];
        if (is_array($raw)) {
            /** @var array<string,mixed> $typed */
            $typed = $raw;
            self::bulkSet($typed);
        }
        unset($GLOBALS['lang']);
    }

    /** @param array<int,string> $days */
    public static function setDays(array $days): void
    {
        self::$days = $days;
    }

    /** @param array<int,string> $months */
    public static function setMonths(array $months): void
    {
        self::$months = $months;
    }

    public static function t(string $key, string|int|float|bool|null ...$args): string
    {
        // Delegate to Translator when PO files are loaded; it falls back to $lang
        // array internally so both pre-boot PHP-file loads and post-boot PO loads work.
        return Translator::get()->translate($key, array_values($args));
    }

    public static function has(string $key): bool
    {
        return isset(self::$data[$key]);
    }

    public static function getRaw(string $key): ?string
    {
        $val = self::$data[$key] ?? null;
        return is_string($val) ? $val : null;
    }

    public static function setString(string $key, string $val): void
    {
        self::$data[$key] = $val;
    }

    /**
     * Bulk-replace the translation data and re-sync day/month arrays.
     * Used by LanguageStack when restoring a saved language state.
     *
     * @param array<string,mixed> $data
     */
    public static function bulkSet(array $data): void
    {
        $days = is_array($data['day'] ?? null) ? $data['day'] : [];
        $months = is_array($data['month'] ?? null) ? $data['month'] : [];
        unset($data['day'], $data['month']);
        /** @var array<string,string> $flat */
        $flat = $data;
        self::$data = $flat;
        $daysOut = [];
        foreach ($days as $k => $v) {
            $daysOut[(int) $k] = is_scalar($v) ? (string) $v : '';
        }
        self::$days = $daysOut;
        $monthsOut = [];
        foreach ($months as $k => $v) {
            $monthsOut[(int) $k] = is_scalar($v) ? (string) $v : '';
        }
        self::$months = $monthsOut;
    }

    /**
     * Returns the complete language state (flat strings + day + month arrays).
     * Used by LanguageStack when saving language state for NBM.
     *
     * @return array<string,mixed>
     */
    public static function all(): array
    {
        $result = self::$data;
        if (self::$days !== []) {
            $result['day'] = self::$days;
        }
        if (self::$months !== []) {
            $result['month'] = self::$months;
        }
        return $result;
    }

    /** @return array<int, string> Month names keyed 1–12. */
    public static function months(): array
    {
        return self::$months;
    }

    /** @return array<int, string> Day names keyed 0 (Sunday) – 6 (Saturday). */
    public static function days(): array
    {
        return self::$days;
    }

    /** Day name by day-of-week index (0 = Sunday). */
    public static function day(int $dow): string
    {
        return self::$days[$dow] ?? '';
    }

    /** Month name by month number (1 = January). */
    public static function month(int $m): string
    {
        return self::$months[$m] ?? '';
    }

    /** @return array<string,mixed> */
    public static function langInfo(): array
    {
        return self::$langInfo;
    }

    /** @param array<string,mixed> $info */
    public static function setLangInfo(array $info): void
    {
        self::$langInfo = $info;
    }

    /** @param array<string,mixed> $additions */
    public static function mergeLangInfo(array $additions): void
    {
        self::$langInfo = array_merge(self::$langInfo, $additions);
    }

    // ---- Test helpers ----------------------------------------------------

    /** @param array<string,mixed> $data */
    public static function loadArray(array $data): void
    {
        self::bulkSet($data);
    }

    public static function reset(): void
    {
        self::$data     = [];
        self::$days     = [];
        self::$months   = [];
        self::$langInfo = [];
    }
}
