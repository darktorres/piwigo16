<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

/**
 * Collects PHP snippets a patch wants appended to the gallery's
 * local/config/database.inc.php. Replaces the legacy
 * `array_push($mysql_changes, '...')` convention: $mysql_changes was a
 * local of UpgradeRunner::performUpgrade()'s include scope, unreachable
 * from a class method, so patches push here and the runner drains this
 * collector into its own list before rewriting the config file. The
 * upgrade_feed path never wrote database.inc.php and simply ignores the
 * collector, matching the original's behavior (a feed-run patch's push
 * went nowhere).
 */
final class DatabaseConfigChanges
{
    /**
     * @var list<string>
     */
    private static array $changes = [];

    public static function push(string $phpSnippet): void
    {
        self::$changes[] = $phpSnippet;
    }

    /**
     * Returns everything pushed so far and empties the collector.
     *
     * @return list<string>
     */
    public static function drain(): array
    {
        $changes = self::$changes;
        self::$changes = [];

        return $changes;
    }
}
