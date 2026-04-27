<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Wave C: wraps $GLOBALS['conf'] in a deprecation-emitting ArrayObject proxy
 * so any remaining direct $conf['key'] access in plugin code or overlooked
 * first-party code is flagged at runtime.
 *
 * The proxy is installed by Kernel::boot() after Config::attachGlobals().
 * New code that uses Config::get() / typed getters never touches the proxy.
 *
 * A backtrace gate silences callers from allowlisted paths (install/db/,
 * local/config/, language/, config_default.inc.php) so the installer and
 * upgrade scripts stay noise-free.
 */
final class GlobalsBridge
{
    /**
     * File-path fragments whose stack frames are exempt from deprecation notices.
     * Covers: upgrade scripts, local config overrides, language files.
     */
    private const SILENT_CALLER_FRAGMENTS = [
        '/install/db/',
        '\\install\\db\\',
        '/include/config_default.inc.php',
        '\\include\\config_default.inc.php',
        '/local/config/',
        '\\local\\config\\',
        '/language/',
        '\\language\\',
    ];

    /** Replace $GLOBALS['conf'] with a deprecation-emitting ConfProxy. */
    public static function installAsConfProxy(): void
    {
        $proxy = new ConfProxy(Config::all());
        // Break the by-reference binding from Config::attachGlobals() and
        // replace with the proxy. Config::$data remains the source of truth;
        // ConfProxy::offsetSet() calls Config::override() to stay in sync.
        unset($GLOBALS['conf']);
        $GLOBALS['conf'] = $proxy;
    }

    /**
     * Inspect the call stack for an allowlisted path fragment.
     * Uses up to 8 frames to cover calls through include chains.
     */
    public static function isSilentCaller(): bool
    {
        $frames = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 8);
        foreach ($frames as $frame) {
            $file = $frame['file'] ?? '';
            if ($file === '') {
                continue;
            }
            foreach (self::SILENT_CALLER_FRAGMENTS as $fragment) {
                if (\str_contains($file, $fragment)) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function emitDeprecation(string $key, string $kind): void
    {
        if (self::isSilentCaller()) {
            return;
        }
        \trigger_error(
            \sprintf(
                'Phase 4 Wave C: %s $conf[\'%s\'] via global is deprecated; '
                . 'use \\Piwigo\\Core\\Config::%s() instead.',
                $kind,
                $key,
                self::suggestGetterName($key)
            ),
            \E_USER_DEPRECATED
        );
    }

    private static function suggestGetterName(string $key): string
    {
        return \lcfirst(\str_replace(' ', '', \ucwords(\str_replace(['_', '-'], ' ', $key))));
    }
}

/**
 * Deprecation-emitting proxy over the $conf global array.
 *
 * Reads emit E_USER_DEPRECATED pointing at Config::key().
 * Writes emit E_USER_DEPRECATED and also call Config::override() so that
 * typed getters stay in sync with any runtime mutations.
 * Existence checks (isset / array_key_exists) are silent — they are
 * inherently read-only and used heavily in guards.
 *
 * @internal
 */
/** @extends \ArrayObject<string,mixed> */
final class ConfProxy extends \ArrayObject
{
    public function offsetGet(mixed $key): mixed
    {
        if (\is_string($key)) {
            GlobalsBridge::emitDeprecation($key, 'read');
        }
        return parent::offsetGet($key);
    }

    public function offsetSet(mixed $key, mixed $value): void
    {
        if (\is_string($key)) {
            GlobalsBridge::emitDeprecation($key, 'write');
        }
        parent::offsetSet($key, $value);
        // Keep Config::$data in sync so typed getters see the mutation.
        if (\is_string($key)) {
            Config::override($key, $value);
        }
    }

    public function offsetExists(mixed $key): bool
    {
        // Existence checks are not deprecated — they are read-only guards.
        return parent::offsetExists($key);
    }

    public function offsetUnset(mixed $key): void
    {
        if (\is_string($key)) {
            GlobalsBridge::emitDeprecation($key, 'unset');
        }
        parent::offsetUnset($key);
        if (\is_string($key)) {
            Config::delete($key);
        }
    }
}
