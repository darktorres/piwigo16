<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Manages the push-down language-switch stack and all mutations of the typed
 * Lang static state ($data, $days, $months, $langInfo) and the plugin-file
 * registry that replaces the legacy $lang / $lang_info / $language_files
 * globals.
 *
 * Design: all reads/writes go through Lang static methods (`bulkSet`,
 * `setString`, `setDays`, `setMonths`, `langInfo`, `setLangInfo`,
 * `mergeLangInfo`). No `$GLOBALS` bridge.
 *
 * Stack state ($stack, $saved, $switchInitialized) lives in static properties
 * since it does not need a global bridge — only switch_lang_to/back read it.
 */
final class LanguageStack
{
    /** @var list<string> */
    private static array $stack = [];

    /**
     * @var array<string, array{lang_info: array<mixed>, lang: array<mixed>}>
     * Snapshots of lang/lang_info per language code, saved on first switch.
     */
    private static array $saved = [];

    private static bool $switchInitialized = false;

    /**
     * Plugin/theme language files registered by LangService::get()->loadLanguage(), keyed by
     * dirname → filename → options. Stored as a private static property so
     * we don't need $GLOBALS['language_files'] for the switch_lang_to reload.
     *
     * @var array<string, array<string, array<mixed>>>
     */
    private static array $pluginFiles = [];

    // -------------------------------------------------------------------------
    // $lang accessors
    // -------------------------------------------------------------------------

    /** @return array<string,mixed> */
    public static function lang(): array
    {
        return Lang::all();
    }

    /**
     * Replace the entire lang data set.
     *
     * @param array<mixed> $lang
     */
    public static function setLang(array $lang): void
    {
        /** @var array<string,mixed> $typedLang */
        $typedLang = $lang;
        Lang::bulkSet($typedLang);
    }

    /**
     * Merge $additions into the current lang data.
     *
     * @param array<string, mixed> $additions
     */
    public static function mergeLang(array $additions): void
    {
        $days = is_array($additions['day'] ?? null) ? $additions['day'] : null;
        $months = is_array($additions['month'] ?? null) ? $additions['month'] : null;
        foreach ($additions as $k => $v) {
            if ($k === 'day' || $k === 'month') {
                continue;
            }
            if (is_string($v)) {
                Lang::setString($k, $v);
            }
        }
        if ($days !== null) {
            $daysOut = [];
            foreach ($days as $k => $v) {
                $daysOut[(int) $k] = is_scalar($v) ? (string) $v : '';
            }
            Lang::setDays($daysOut);
        }
        if ($months !== null) {
            $monthsOut = [];
            foreach ($months as $k => $v) {
                $monthsOut[(int) $k] = is_scalar($v) ? (string) $v : '';
            }
            Lang::setMonths($monthsOut);
        }
    }

    // -------------------------------------------------------------------------
    // $lang_info accessors
    // -------------------------------------------------------------------------

    /** @return array<mixed> */
    public static function info(): array
    {
        return Lang::langInfo();
    }

    /** Returns true once at least one language file has been loaded. */
    public static function initialized(): bool
    {
        return Lang::langInfo() !== [];
    }

    /** @param array<string, mixed> $info */
    public static function setInfo(array $info): void
    {
        Lang::setLangInfo($info);
    }

    /** @param array<string, mixed> $additions */
    public static function mergeInfo(array $additions): void
    {
        Lang::mergeLangInfo($additions);
    }

    // -------------------------------------------------------------------------
    // Plugin-file tracking (was $language_files global)
    // -------------------------------------------------------------------------

    /** @return array<string, array<string, array<mixed>>> */
    public static function pluginFiles(): array
    {
        return self::$pluginFiles;
    }

    /** @param array<mixed> $options */
    public static function trackPluginFile(string $dirname, string $filename, array $options): void
    {
        self::$pluginFiles[$dirname][$filename] = $options;
    }

    public static function hasPluginFile(string $dirname, string $filename): bool
    {
        return isset(self::$pluginFiles[$dirname][$filename]);
    }

    // -------------------------------------------------------------------------
    // Include helper
    // -------------------------------------------------------------------------

    /**
     * Include a language file in an isolated scope and merge its $lang / $lang_info
     * into the current global state. Used to load parent languages without leaking
     * local variables into the caller.
     */
    public static function mergeFromFile(string $path): void
    {
        $lang = [];
        $lang_info = [];
        if (is_readable($path)) {
            /** @psalm-suppress UnresolvableInclude */
            include $path;
        }
        self::mergeLang($lang);
        self::mergeInfo($lang_info);
    }

    // -------------------------------------------------------------------------
    // Push-down stack (switch_lang_to / switch_lang_back)
    // -------------------------------------------------------------------------

    /** Save a snapshot of the current lang/lang_info for language $code. */
    public static function saveState(string $code): void
    {
        self::$saved[$code] = [
            'lang_info' => self::info(),
            'lang' => self::lang(),
        ];
    }

    public static function hasSavedState(string $code): bool
    {
        return isset(self::$saved[$code]);
    }

    /**
     * Restore lang/lang_info from the saved snapshot for $code, in-place
     * to preserve any reference bridges.
     */
    public static function restoreState(string $code): void
    {
        if (!isset(self::$saved[$code])) {
            return;
        }
        self::setLang(self::$saved[$code]['lang']);
        /** @var array<string,mixed> $info */
        $info = self::$saved[$code]['lang_info'];
        Lang::setLangInfo($info);
    }

    public static function pushStack(string $code): void
    {
        self::$stack[] = $code;
    }

    public static function popStack(): ?string
    {
        return empty(self::$stack) ? null : array_pop(self::$stack);
    }

    public static function isSwitchInitialized(): bool
    {
        return self::$switchInitialized;
    }

    public static function markSwitchInitialized(): void
    {
        self::$switchInitialized = true;
    }

    // ---- Test helpers -------------------------------------------------------

    public static function reset(): void
    {
        self::$stack = [];
        self::$saved = [];
        self::$switchInitialized = false;
        self::$pluginFiles = [];
    }
}
