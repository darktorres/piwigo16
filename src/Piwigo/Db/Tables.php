<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Piwigo\Core\Kernel;

/**
 * Table-name resolution for procedural code that writes raw SQL outside a
 * repository -- deferred at P14 ("no real consumer yet"), finished here now
 * that P16's 52-`define()` retirement gives it 34 real callers (one per
 * origin table's legacy TABLE_NAME constant). Also covers the 7 new P15
 * tables, which never had legacy constants but complete the utility for
 * future P17-23 callers -- cheap to include now; the class has 39 static
 * methods, 2 short of the reference's final 41-method shape.
 *
 * ORM repositories never need this -- they address tables via entity
 * mapping (`#[ORM\Table(name: ...)]`, prefix applied by
 * Piwigo\Db\TablePrefixListener). Deleted alongside the `AbstractRepository`
 * DBAL shim in P23 when `include/` is removed.
 */
final class Tables
{
    /**
     * Direct container resolve, not the DbCredentials::current() shim --
     * this class is a purely static utility (39 methods, no instance to
     * speak of, see this class's own docblock), matching FilesystemHelper's
     * own established "no wrapper instance" precedent. Mirrors
     * DbCredentials::current()'s own graceful degradation (a fresh
     * fromEnv() read, not a throw) when Kernel isn't booted -- the vast
     * majority of this class's own callers are plain Unit tests that never
     * boot a Kernel at all.
     */
    private static function dbCredentials(): DbCredentials
    {
        if (Kernel::isBooted()) {
            $dbCredentials = Kernel::container()->get(DbCredentials::class);
            if ($dbCredentials instanceof DbCredentials) {
                return $dbCredentials;
            }
        }

        return DbCredentials::fromEnv();
    }

    public static function activity(): string
    {
        return self::dbCredentials()->prefix . 'activity';
    }

    public static function auditLog(): string
    {
        return self::dbCredentials()->prefix . 'audit_log';
    }

    public static function caddie(): string
    {
        return self::dbCredentials()->prefix . 'caddie';
    }

    public static function categories(): string
    {
        return self::dbCredentials()->prefix . 'categories';
    }

    public static function comments(): string
    {
        return self::dbCredentials()->prefix . 'comments';
    }

    public static function config(): string
    {
        return self::dbCredentials()->prefix . 'config';
    }

    public static function derivativeSettings(): string
    {
        return self::dbCredentials()->prefix . 'derivative_settings';
    }

    public static function derivativeSize(): string
    {
        return self::dbCredentials()->prefix . 'derivative_size';
    }

    public static function extensionIgnoredUpdates(): string
    {
        return self::dbCredentials()->prefix . 'extension_ignored_updates';
    }

    public static function favorites(): string
    {
        return self::dbCredentials()->prefix . 'favorites';
    }

    public static function groupAccess(): string
    {
        return self::dbCredentials()->prefix . 'group_access';
    }

    public static function groups(): string
    {
        return self::dbCredentials()->prefix . 'groups';
    }

    public static function history(): string
    {
        return self::dbCredentials()->prefix . 'history';
    }

    public static function historySummary(): string
    {
        return self::dbCredentials()->prefix . 'history_summary';
    }

    public static function imageCategory(): string
    {
        return self::dbCredentials()->prefix . 'image_category';
    }

    public static function imageFormat(): string
    {
        return self::dbCredentials()->prefix . 'image_format';
    }

    public static function imageTag(): string
    {
        return self::dbCredentials()->prefix . 'image_tag';
    }

    public static function images(): string
    {
        return self::dbCredentials()->prefix . 'images';
    }

    public static function integrityIgnoredAnomalies(): string
    {
        return self::dbCredentials()->prefix . 'integrity_ignored_anomalies';
    }

    public static function languages(): string
    {
        return self::dbCredentials()->prefix . 'languages';
    }

    public static function lounge(): string
    {
        return self::dbCredentials()->prefix . 'lounge';
    }

    public static function oldPermalinks(): string
    {
        return self::dbCredentials()->prefix . 'old_permalinks';
    }

    public static function pluginMigrations(): string
    {
        return self::dbCredentials()->prefix . 'plugin_migrations';
    }

    public static function plugins(): string
    {
        return self::dbCredentials()->prefix . 'plugins';
    }

    public static function rate(): string
    {
        return self::dbCredentials()->prefix . 'rate';
    }

    public static function search(): string
    {
        return self::dbCredentials()->prefix . 'search';
    }

    public static function searchFilterView(): string
    {
        return self::dbCredentials()->prefix . 'search_filter_view';
    }

    public static function sessions(): string
    {
        return self::dbCredentials()->prefix . 'sessions';
    }

    public static function sites(): string
    {
        return self::dbCredentials()->prefix . 'sites';
    }

    public static function tags(): string
    {
        return self::dbCredentials()->prefix . 'tags';
    }

    public static function themes(): string
    {
        return self::dbCredentials()->prefix . 'themes';
    }

    public static function userAccess(): string
    {
        return self::dbCredentials()->prefix . 'user_access';
    }

    public static function userAuthKeys(): string
    {
        return self::dbCredentials()->prefix . 'user_auth_keys';
    }

    public static function userFailedLogins(): string
    {
        return self::dbCredentials()->prefix . 'user_failed_logins';
    }

    public static function userFeed(): string
    {
        return self::dbCredentials()->prefix . 'user_feed';
    }

    public static function userGroup(): string
    {
        return self::dbCredentials()->prefix . 'user_group';
    }

    public static function userInfos(): string
    {
        return self::dbCredentials()->prefix . 'user_infos';
    }

    public static function userMailNotification(): string
    {
        return self::dbCredentials()->prefix . 'user_mail_notification';
    }

    public static function users(): string
    {
        return self::dbCredentials()->prefix . 'users';
    }
}
