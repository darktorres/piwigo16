<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Read-only feature-flag checks, backed by FeatureFlagDefinitions.
 *
 * SEC-58's own threat ("unauthorized feature-flag change") needs authz,
 * which needs CurrentUser -- a service with no write/toggle path has
 * no "unauthorized change" surface to protect yet. The admin-facing
 * mutation capability (admin UI + CurrentUser + audit_log,
 * SEC-57) is a future addition.
 */
final class FeatureFlag
{
    /**
     * `$overrideFlags` defaults to null and falls back to the real
     * `FeatureFlagDefinitions::all()`, mirroring
     * `CliBootstrap::buildApplication()`'s `$overrideCommands` parameter --
     * the only seam for tests/Unit/Core/FeatureFlagTest.php to exercise
     * the `=== true` (not merely truthy) guard against a disposable
     * fixture array instead of ever having to overwrite the real, shared
     * production flag list. Typed `array<string, mixed>`, not
     * `array<string, bool>` -- the guard exists precisely because PHP
     * doesn't enforce a generic array's value types at runtime, so the
     * test deliberately passes non-bool values (e.g. 'yes', 1) to prove
     * the guard catches what the type system alone cannot.
     *
     * @param array<string, mixed>|null $overrideFlags
     */
    public static function isEnabled(string $flag, ?array $overrideFlags = null): bool
    {
        $flags = $overrideFlags ?? FeatureFlagDefinitions::all();

        return ($flags[$flag] ?? false) === true;
    }
}
