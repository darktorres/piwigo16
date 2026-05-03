<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * Thin OO facade over the conf-table free functions in
 * include/functions.inc.php. Provides a stable injection point that typed
 * Config classes (Piwigo's own + per-plugin classes) can use to persist /
 * load / delete config values without depending directly on the legacy
 * free-function API.
 *
 * Does NOT validate keys against any SCHEMA — each calling Config class
 * is responsible for its own typed accessors and validation. ConfigStorage
 * is the storage backend; Config classes are the typed read/write API.
 *
 * The free functions stay (legacy code in admin/ and include/ depends on
 * them). Eventually they can become thin wrappers around this class once
 * all callers migrate.
 */
final class ConfigStorage
{
    /**
     * Bulk read from the conf table into $GLOBALS['conf'] (and through the
     * reference bridge into Config::$data after Kernel::boot()).
     *
     * @param string|null $whereCondition optional SQL `WHERE` fragment
     *                    (without the `WHERE` keyword), e.g., "param LIKE 'plugin_%'"
     * @param bool        $dieOnEmpty     if a non-empty $whereCondition produces
     *                                    no rows, fatal_error() is raised
     */
    public static function loadAll(?string $whereCondition = null, bool $dieOnEmpty = true): void
    {
        \load_conf_from_db($whereCondition ?? '', $dieOnEmpty);
    }

    /**
     * Persist a single key/value to the conf table.
     *
     * @param string                 $key        config key
     * @param mixed                  $value      raw PHP value (arrays/objects auto-serialized)
     * @param callable-string|null   $serializer optional pre-serializer (e.g., 'serialize', 'json_encode')
     */
    public static function persist(string $key, mixed $value, ?string $serializer = null): void
    {
        \conf_update_param($key, $value, false, $serializer);
    }

    /**
     * Delete one or more keys from the conf table.
     *
     * @param string|list<string> $keys
     */
    public static function delete(string|array $keys): void
    {
        \conf_delete_param($keys);
    }

    /**
     * Round-trip check: writes a probe key, reads it back, deletes it.
     * Used by the maintenance UI to detect a read-only conf table.
     */
    public static function isWriteable(): bool
    {
        return \pwg_is_dbconf_writeable();
    }
}
