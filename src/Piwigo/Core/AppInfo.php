<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Typed replacement for 4 of the 52 `define()` constants retired from
 * `include/constants.php` (PHPWG_VERSION/PHPWG_DEFAULT_LANGUAGE/
 * PHPWG_DEFAULT_TEMPLATE/REQUIRED_PHP_VERSION) -- verified against the
 * reference's real, stable retirement commit, not invented fresh.
 */
final class AppInfo
{
    // Matches include/constants.php's real PHPWG_VERSION value exactly --
    // the app/codebase version Piwigo itself tracks (compared against
    // $conf['piwigo_db_version'] to trigger upgrade.php), NOT this
    // project's own "17.x-rewrite" branch/milestone name. Confirmed via a
    // real regression: an initial '17.0.0' guess here sent every request
    // into an upgrade.php redirect loop once real callers (common.inc.php's
    // version check) started reading this instead of the bare constant.
    public const string VERSION = '16.3.0';

    public const string DEFAULT_LANGUAGE = 'en_UK';

    public const string DEFAULT_TEMPLATE = 'modus';

    public const string REQUIRED_PHP_VERSION = '8.5.0';
}
