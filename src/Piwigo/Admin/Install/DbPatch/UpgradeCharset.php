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
 * Charset state resolved mid-upgrade by Patch65 (the 2.0-era "PWG charset
 * migration"). The original install/db/65-database.php define()d
 * PWG_CHARSET/DB_CHARSET at runtime so that LATER patches in the same
 * upgrade run (85, 90) could read them on pre-2.0 databases where the
 * upgrade.php shell had not defined them; SEC-60 forbids define() inside
 * src/Piwigo, so the same single-write/multi-read shape lives here as
 * typed static state instead (precedent: the reference branch replaced
 * the PHPWG_IN_UPGRADE constant with UpgradeService::$upgradeAuthorized
 * the same way). The shell-define()d constants still win when present --
 * patches consult these accessors, everything outside the patch flow
 * keeps reading the constants.
 */
final class UpgradeCharset
{
    private static ?string $pwgCharset = null;

    private static ?string $dbCharset = null;

    public static function set(string $pwgCharset, string $dbCharset): void
    {
        self::$pwgCharset = $pwgCharset;
        self::$dbCharset = $dbCharset;
    }

    /**
     * True once the run's charset is known -- either the entry shell
     * define()d PWG_CHARSET (modern databases) or Patch65 resolved it
     * mid-run (pre-2.0 databases). Patch65's own "already defined - nada"
     * guard reads this.
     */
    public static function isResolved(): bool
    {
        return defined('PWG_CHARSET') || self::$pwgCharset !== null;
    }

    public static function pwgCharset(): string
    {
        if (defined('PWG_CHARSET') && is_string(PWG_CHARSET)) {
            return PWG_CHARSET;
        }

        return self::$pwgCharset ?? 'utf-8';
    }

    public static function dbCharset(): string
    {
        if (defined('DB_CHARSET') && is_string(DB_CHARSET)) {
            return DB_CHARSET;
        }

        return self::$dbCharset ?? 'utf8';
    }
}
