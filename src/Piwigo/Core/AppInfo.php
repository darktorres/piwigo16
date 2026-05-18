<?php

declare(strict_types=1);

namespace Piwigo\Core;

final class AppInfo
{
    public const string VERSION                = '17.0.0';
    public const string DEFAULT_LANGUAGE       = 'en_UK';
    public const string DEFAULT_TEMPLATE       = 'modus';
    public const string REQUIRED_PHP_VERSION   = '8.5.0';
    /** Minimum server version: MySQL 8.0+ or MariaDB 10.5+. */
    public const string REQUIRED_MYSQL_VERSION = '8.0.0';

    /**
     * Base URL of the fork's project website. Placeholder pending the
     * fork-site launch; readers compose suffixes like `/forum`, `/doc`,
     * `/releases/X`. The `.example` TLD is reserved by RFC 2606 so the
     * placeholder never resolves to a real host — outbound telemetry
     * and version-check requests fail closed, same effect as the legacy
     * `define('PHPWG_URL', '')` it replaces.
     */
    public const string PROJECT_URL = 'https://piwigo.example';

    public static function branchFromVersion(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 1));
    }
}
