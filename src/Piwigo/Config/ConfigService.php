<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * The DI/Doctrine-backed config persistence layer -- the real writer
 * behind Piwigo\Config\Config:: (Legacy Coupling Retirement Phase 5).
 * Three-part split, each part with a real reason to exist:
 * Piwigo\Config\Config:: is the static typed read layer (SCHEMA-driven
 * accessors) and in-memory-only write via Config::override();
 * Piwigo\Config\ConfigService (this class) is the DI/Doctrine-backed
 * persistence layer, constructor-injected into every real caller that
 * can reach the container; Piwigo\Config\ConfigDb is the static
 * DBAL-direct persistence layer for the callers that structurally
 * cannot -- pre-container bootstrap/install/upgrade code and the frozen
 * DbPatch/VersionUpgrade set. Not a "deferred to P14" gap: P14 (DB layer
 * + Doctrine ORM) has been done for a long time, and this class's write
 * methods already route through a real Doctrine entity
 * (ConfigEntry/ConfigRepository, mapping the `config` table), matching
 * every other domain's repository pattern in this codebase.
 *
 * loadConfFromDb()/confUpdateParam() faithfully replicate the CURRENT
 * legacy encoding (include/functions.inc.php's load_conf_from_db()/
 * conf_update_param()): scalars as literal strings, 'true'/'false'
 * coerced to bool on read, arrays/objects as serialize() blobs -- NOT the
 * reference's JSON-decode logic, since config.value is still plain TEXT
 * at this point in the replay (the text->JSON conversion is one of the 43
 * column-type changes deferred to P17-23, per docs/PLAN-REPLAY.md's own
 * P15 section). No addslashes() -- Doctrine parameterizes values safely,
 * that legacy escaping was only ever needed for raw SQL string
 * concatenation.
 */
final readonly class ConfigService
{
    public function __construct(
        private ConfigRepository $repo,
    ) {}

    /**
     * Generic dynamic-key reader for conf rows that legitimately lack a
     * SCHEMA entry. For SCHEMA-backed keys, prefer the typed Config::xxx()
     * accessors.
     *
     * @param array<mixed>|string|int|float|bool|null $defaultValue
     */
    public function confGetParam(string $param, array|string|int|float|bool|null $defaultValue = null): mixed
    {
        return Config::all()[$param] ?? $defaultValue;
    }

    /**
     * Merges config table rows into Config::$data. $param === null loads
     * every row (the common case -- every real call site except one);
     * a non-null $param loads just that one row by primary key. Narrower
     * than the legacy load_conf_from_db()'s arbitrary raw-SQL $condition
     * string -- grepping every real call site found exactly one
     * non-trivial usage (a single-param equality filter), so a
     * parameterized find() covers the real need without a string-
     * interpolation surface.
     *
     * Fires the same 'load_conf' plugin hook ConfigDb::loadConfFromDb()
     * does, payload $param in place of that method's raw SQL $condition
     * string (the closest equivalent under this method's narrower
     * by-single-key signature) -- a documented trigger
     * (tools/triggers_list.php), so a plugin listening for it must keep
     * being notified regardless of which persistence layer a given
     * caller routes through.
     */
    public function loadConfFromDb(?string $param = null, bool $dieIfNotFound = true): void
    {
        if ($param === null) {
            $entries = $this->repo->findAll();
        } else {
            $entry = $this->repo->find($param);
            if ($entry === null) {
                if ($dieIfNotFound) {
                    throw new \RuntimeException("No configuration data for param '{$param}'.");
                }
                return;
            }
            $entries = [$entry];
        }

        foreach ($entries as $entry) {
            Config::override($entry->param, self::decodeScalar($entry->value));
        }

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('load_conf', $param);
    }

    /**
     * Persist a single key/value to the conf table.
     *
     * @param array<mixed>|string|int|float|bool|null $value
     */
    public function confUpdateParam(string $param, array|string|int|float|bool|null $value, bool $updateGlobal = false): void
    {
        $this->repo->upsert($param, self::encode($value));

        if ($updateGlobal) {
            Config::override($param, $value);
        }
    }

    /**
     * @param string|list<string> $params
     */
    public function confDeleteParam(string|array $params): void
    {
        $params = is_array($params) ? $params : [$params];
        foreach ($params as $param) {
            $this->repo->deleteByParam($param);
            Config::delete($param);
        }
    }

    /**
     * Writes a random probe value, reads it back, deletes it.
     */
    public function pwgIsDbconfWriteable(): bool
    {
        $param = 'pwg_is_dbconf_writeable_' . bin2hex(random_bytes(6));
        $value = date('c') . ' ' . bin2hex(random_bytes(10));

        $this->confUpdateParam($param, $value);
        $entry = $this->repo->find($param);
        $writeable = $entry !== null && $entry->value === $value;

        $this->confDeleteParam($param);

        return $writeable;
    }

    private static function decodeScalar(?string $raw): string|bool|null
    {
        if ($raw === 'true') {
            return true;
        }
        if ($raw === 'false') {
            return false;
        }
        return $raw;
    }

    /**
     * @param array<mixed>|string|int|float|bool|null $value
     */
    private static function encode(array|string|int|float|bool|null $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return serialize($value);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }
}
