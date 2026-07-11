<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Read-only feature-flag checks, backed by config/feature-flags.php.
 *
 * SEC-58's own threat ("unauthorized feature-flag change") needs authz,
 * which needs CurrentUser (P16) -- a service with no write/toggle path has
 * no "unauthorized change" surface to protect yet. The admin-facing
 * mutation capability (P21 admin UI + P16 CurrentUser + audit_log,
 * P13-16/SEC-57) is a later phase's job.
 */
final class FeatureFlag
{
    public static function isEnabled(string $flag): bool
    {
        $flags = require dirname(__DIR__, 3) . '/config/feature-flags.php';
        if (! is_array($flags)) {
            throw new \RuntimeException('config/feature-flags.php must return an array of flag => bool.');
        }

        return ($flags[$flag] ?? false) === true;
    }
}
