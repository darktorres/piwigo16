<?php

declare(strict_types=1);

namespace Piwigo\Core;

use RuntimeException;

/**
 * Read-only feature-flag checks, backed by config/feature-flags.php.
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
     * `$configPath` defaults to null and falls back to the real
     * `config/feature-flags.php`, mirroring `CliBootstrap::buildApplication()`'s
     * `$commandsFile` parameter -- the only seam for
     * tests/Unit/Core/FeatureFlagTest.php to exercise the
     * must-return-an-array guard against a disposable fixture file instead
     * of ever having to overwrite the real, shared production file
     * mid-suite.
     */
    public static function isEnabled(string $flag, ?string $configPath = null): bool
    {
        $flags = require $configPath ?? dirname(__DIR__, 3) . '/config/feature-flags.php';
        if (! is_array($flags)) {
            throw new RuntimeException('config/feature-flags.php must return an array of flag => bool.');
        }

        return ($flags[$flag] ?? false) === true;
    }
}
