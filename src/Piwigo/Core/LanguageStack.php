<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Manages the push-down language-switch stack and all mutations of the $lang,
 * $lang_info and $language_files globals, replacing those three global declarations
 * across functions_mail.inc.php, functions.inc.php, and functions_notification_by_mail.inc.php.
 *
 * Design: no reference bridge for $lang/$lang_info. All reads go through $GLOBALS
 * directly so that the existing Lang::attachGlobals() reference bridge (which binds
 * $GLOBALS['lang'] to Lang::$data) stays intact. Mutations use in-place array
 * modification (not assignment) to preserve that reference bridge after Kernel::boot().
 *
 * Stack state ($stack, $saved, $switchInitialized) lives in static properties since
 * it does not need a global bridge — only switch_lang_to/back read it.
 */
final class LanguageStack
{
    /** @var list<string> */
    private static array $stack = [];

    /**
     * @var array<string, array{lang_info: array<string,mixed>, lang: array<string,mixed>}>
     * Snapshots of lang/lang_info per language code, saved on first switch.
     */
    private static array $saved = [];

    private static bool $switchInitialized = false;

    /**
     * Plugin/theme language files registered by load_language(), keyed by
     * dirname → filename → options. Stored as a private static property so
     * we don't need $GLOBALS['language_files'] for the switch_lang_to reload.
     *
     * @var array<string, array<string, array<mixed>>>
     */
    private static array $pluginFiles = [];

    // -------------------------------------------------------------------------
    // $lang accessors
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public static function lang(): array
    {
        $raw = $GLOBALS['lang'] ?? [];
        return is_array($raw) ? $raw : [];
    }

    /**
     * Replace the entire $lang array in-place, preserving any reference bridge
     * (e.g. the one set by Lang::attachGlobals() to Lang::$data).
     *
     * @param array<string, mixed> $lang
     */
    public static function setLang(array $lang): void
    {
        $ref = &$GLOBALS['lang'];
        if (!is_array($ref)) {
            $GLOBALS['lang'] = $lang;
            return;
        }
        foreach (array_keys($ref) as $k) {
            unset($ref[$k]);
        }
        foreach ($lang as $k => $v) {
            $ref[$k] = $v;
        }
    }

    /**
     * Merge $additions into the current $lang in-place.
     *
     * @param array<string, mixed> $additions
     */
    public static function mergeLang(array $additions): void
    {
        $ref = &$GLOBALS['lang'];
        if (!is_array($ref)) {
            $GLOBALS['lang'] = $additions;
            return;
        }
        foreach ($additions as $k => $v) {
            $ref[$k] = $v;
        }
    }

    // -------------------------------------------------------------------------
    // $lang_info accessors
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public static function info(): array
    {
        $raw = $GLOBALS['lang_info'] ?? [];
        return is_array($raw) ? $raw : [];
    }

    /** Returns true once at least one language file has been loaded. */
    public static function initialized(): bool
    {
        return self::info() !== [];
    }

    /** @param array<string, mixed> $info */
    public static function setInfo(array $info): void
    {
        $GLOBALS['lang_info'] = $info;
    }

    /** @param array<string, mixed> $additions */
    public static function mergeInfo(array $additions): void
    {
        $current = is_array($GLOBALS['lang_info'] ?? null) ? $GLOBALS['lang_info'] : [];
        $GLOBALS['lang_info'] = array_merge($current, $additions);
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
        @include($path);
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
        $GLOBALS['lang_info'] = self::$saved[$code]['lang_info'];
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
