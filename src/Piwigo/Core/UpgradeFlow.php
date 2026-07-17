<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Core;

/**
 * "This request is the upgrade flow" marker (P23 sub-batch 8g-6). The
 * upgrade.php/upgrade_feed.php entry shells historically define()d
 * UPGRADES_PATH, and Lang::loadLanguage() used defined('UPGRADES_PATH')
 * to avoid querying the mid-migration database for the default language;
 * the constant died with the install/db directory, so the same
 * single-write/multi-read marker lives here as typed static state
 * (precedent: UpgradeCharset replacing the PWG_CHARSET mid-run defines).
 * Lives in Core because the reader (Lang, L1Infrastructure) may not
 * depend on Piwigo\Admin (L4).
 */
final class UpgradeFlow
{
    private static bool $active = false;

    /**
     * Called once at the top of the upgrade.php/upgrade_feed.php entry
     * shells, exactly where the former define() sat.
     */
    public static function mark(): void
    {
        self::$active = true;
    }

    public static function isActive(): bool
    {
        return self::$active;
    }
}
