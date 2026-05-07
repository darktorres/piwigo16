<?php

declare(strict_types=1);

namespace Piwigo\Core;

final class AppInfo
{
    public const string VERSION              = '16.3.0';
    public const string DEFAULT_LANGUAGE     = 'en_UK';
    public const string DEFAULT_TEMPLATE     = 'modus';
    public const string REQUIRED_PHP_VERSION = '8.5.0';

    public static function branchFromVersion(mixed $version): string
    {
        return implode('.', array_slice(explode('.', is_scalar($version) ? (string) $version : ''), 0, 1));
    }
}
