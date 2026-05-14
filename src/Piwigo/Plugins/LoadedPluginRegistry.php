<?php

declare(strict_types=1);

namespace Piwigo\Plugins;

/** Static registry for loaded plugins. */
final class LoadedPluginRegistry
{
    /** @var array<string, array<string, mixed>> */
    private static array $plugins = [];

    public static function init(): void
    {
        self::$plugins = [];
    }

    /** @param array<string, mixed> $plugin */
    public static function register(string $id, array $plugin): void
    {
        self::$plugins[$id] = $plugin;
    }

    public static function isLoaded(string $id): bool
    {
        return isset(self::$plugins[$id]);
    }

    /** @return array<string, mixed>|null */
    public static function get(string $id): ?array
    {
        $entry = self::$plugins[$id] ?? null;
        return is_array($entry) ? $entry : null;
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::$plugins;
    }

    public static function setData(string $id, mixed &$data): bool
    {
        if (!isset(self::$plugins[$id])) {
            return false;
        }
        self::$plugins[$id]['plugin_data'] = &$data;
        return true;
    }

    public static function &getData(string $id): mixed
    {
        if (!isset(self::$plugins[$id]['plugin_data'])) {
            $null = null;
            return $null;
        }
        $ref = &self::$plugins[$id]['plugin_data'];
        return $ref;
    }

    public static function reset(): void
    {
        self::$plugins = [];
    }
}
