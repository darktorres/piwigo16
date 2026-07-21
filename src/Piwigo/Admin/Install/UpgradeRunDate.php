<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install;

/**
 * The single `SELECT NOW()` timestamp upgrade.php fetches once per run, so
 * every ledger row a version-upgrade step inserts during that run shares
 * the exact same "applied" moment rather than drifting row to row.
 * Legacy Coupling Retirement gap-closure (install/upgrade-flow constants
 * round): this used to be a `define('CURRENT_DATE', $dbnow)` global (its
 * own comment claimed "the frozen install/upgrade_X.Y.Z.php scripts read
 * it at include time" -- those scripts don't exist any more, P23
 * sub-batch 8g already ported them to real VersionUpgrade classes) --
 * same single-write/multi-read shape `UpgradeCharset` already uses for
 * `PWG_CHARSET`/`DB_CHARSET`, applied here since the one real reader
 * (`AbstractRangeVersionUpgrade`) is a real class too.
 */
final class UpgradeRunDate
{
    private static ?string $date = null;

    public static function set(string $date): void
    {
        self::$date = $date;
    }

    /**
     * @throws \LogicException if upgrade.php's own SELECT NOW() seeding
     *                          never ran -- unlike UpgradeCharset's
     *                          'utf-8'/'utf8' fallbacks, there's no
     *                          sensible default applied-date to fall
     *                          back to; a missing value here is a real
     *                          bug in the upgrade run's own ordering, not
     *                          a gap to paper over.
     */
    public static function get(): string
    {
        return self::$date ?? throw new \LogicException('UpgradeRunDate::get() called before set() -- upgrade.php must seed this before any VersionUpgrade step runs.');
    }
}
