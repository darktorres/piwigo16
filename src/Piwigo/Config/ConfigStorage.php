<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Piwigo\Core\Kernel;

/**
 * Storage backend for the conf table. Typed Config classes (Piwigo's own +
 * per-plugin classes) call ConfigStorage to persist / load / delete config
 * rows without depending on any free-function API.
 *
 * Does NOT validate keys against any SCHEMA — each calling Config class is
 * responsible for its own typed accessors and validation. ConfigStorage is
 * the storage backend; Config classes are the typed read/write API.
 */
final class ConfigStorage
{
    /**
     * Bulk read from the conf table into Config::$data.
     *
     * @param string|null $whereCondition optional SQL `WHERE` fragment
     *                    (without the `WHERE` keyword), e.g., "param LIKE 'plugin_%'"
     * @param bool        $dieOnEmpty     if a non-empty $whereCondition produces
     *                                    no rows, fatal_error() is raised
     */
    public static function loadAll(?string $whereCondition = null, bool $dieOnEmpty = true): void
    {
        ConfigService::loadConfFromDb($whereCondition ?? '', $dieOnEmpty);
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
        Kernel::service(ConfigService::class)->confUpdateParam($key, $value, false, $serializer);
    }

    /**
     * Delete one or more keys from the conf table.
     *
     * @param string|list<string> $keys
     */
    public static function delete(string|array $keys): void
    {
        Kernel::service(ConfigService::class)->confDeleteParam($keys);
    }

    /**
     * Round-trip check: writes a probe key, reads it back, deletes it.
     * Used by the maintenance UI to detect a read-only conf table.
     */
    public static function isWriteable(): bool
    {
        return Kernel::service(ConfigService::class)->pwgIsDbconfWriteable();
    }
}
