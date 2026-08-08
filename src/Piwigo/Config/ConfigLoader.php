<?php

declare(strict_types=1);

namespace Piwigo\Config;

use ReflectionClass;
use ReflectionProperty;

/**
 * Boot-time orchestration for CurrentConfig.
 *
 * Deliberately does NOT own env-FILE loading (no loadEnv(), no TestMode
 * class) -- unlike the reference implementation, which pulls in
 * vlucas/phpdotenv + its own Piwigo\Config\TestMode. This project already
 * has a working mechanism, compliant with docs/REFERENCE.md's
 * native-platform-first library policy, built on the already-adopted
 * symfony/dotenv: Piwigo\Core\Env::loadEnvFile(), called by every real
 * entry point before Config/Kernel ever boot. applyEnvOverrides() below
 * just reads the already-loaded getenv() values.
 */
final class ConfigLoader
{
    /**
     * Currently a genuine no-op: every CurrentConfig property already
     * carries its own default as its property initializer (Config
     * generic-accessor removal retyped the former SCHEMA-driven
     * Config::$data bag into real typed properties), so there is nothing
     * left to "seed" -- a property has a real value from the moment the
     * class loads, not an absent key falling back to a default on every
     * read. Kept as a real, callable step in the standard boot sequence
     * (applyDefaults() -> applyEnvOverrides() -> Kernel::boot() ->
     * ConfigService::loadConfFromDb()) so none of this method's many real
     * call sites need touching.
     */
    public static function applyDefaults(): void {}

    /**
     * Currently a genuine no-op: the only entries this ever mapped (the 7
     * PIWIGO_DB_* vars) moved to Piwigo\Db\DbCredentials (Config generic-
     * accessor removal, which reads them directly and has no relationship
     * to CurrentConfig), and no other property has needed runtime env
     * override since. Kept as a real, callable step in the standard boot
     * sequence (applyDefaults() -> applyEnvOverrides() -> ...) so none of
     * this method's many real call sites need touching if a future
     * property needs this again -- reintroduce a real mechanism then
     * (e.g. a #[EnvOverride('VAR')] property attribute, matching the
     * Required/Sensitive attribute design) rather than a parallel array
     * that can silently drift out of sync with the properties themselves.
     */
    public static function applyEnvOverrides(): void {}

    /**
     * Assert that every #[Required] CurrentConfig property has a
     * non-empty value. NOT called from the live boot sequence yet --
     * secretKey (required) has no resolvable source until either a real
     * per-install secret-generation step or a PIWIGO_SECRET_KEY env-var
     * convention lands; calling this today would throw on every real
     * request. Available for callers (tests, future phases) that have a
     * fully-resolved CurrentConfig.
     *
     * @throws MissingRequiredConfigException
     */
    public static function validateRequired(CurrentConfig $currentConfig): void
    {
        $reflection = new ReflectionClass($currentConfig);
        foreach ($reflection->getProperties(ReflectionProperty::IS_PRIVATE) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            if ($property->getAttributes(Required::class) === []) {
                continue;
            }
            $value = $property->getValue($currentConfig);
            if ($value === null || $value === '') {
                throw new MissingRequiredConfigException(
                    "Required config property '{$property->getName()}' is missing or empty."
                );
            }
        }
    }
}
