<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Piwigo\Config\Config;

/**
 * Table-name resolution for procedural code that writes raw SQL outside a
 * repository -- deferred at P14 ("no real consumer yet"), finished here now
 * that P16's 52-`define()` retirement gives it 34 real callers (one per
 * origin table's legacy TABLE_NAME constant). Also covers the 7 new P15
 * tables, which never had legacy constants but complete the utility for
 * future P17-23 callers -- cheap to include now, matches the reference's
 * real, final 41-method shape.
 *
 * ORM repositories never need this -- they address tables via entity
 * mapping (`#[ORM\Table(name: ...)]`, prefix applied by
 * Piwigo\Db\TablePrefixListener). Deleted alongside the `AbstractRepository`
 * DBAL shim in P23 when `include/` is removed.
 */
final class Tables
{
    public static function activity(): string
    {
        return Config::dbPrefix() . 'activity';
    }

    public static function auditLog(): string
    {
        return Config::dbPrefix() . 'audit_log';
    }

    public static function caddie(): string
    {
        return Config::dbPrefix() . 'caddie';
    }

    public static function categories(): string
    {
        return Config::dbPrefix() . 'categories';
    }

    public static function comments(): string
    {
        return Config::dbPrefix() . 'comments';
    }

    public static function config(): string
    {
        return Config::dbPrefix() . 'config';
    }

    public static function derivativeSettings(): string
    {
        return Config::dbPrefix() . 'derivative_settings';
    }

    public static function derivativeSize(): string
    {
        return Config::dbPrefix() . 'derivative_size';
    }

    public static function extensionIgnoredUpdates(): string
    {
        return Config::dbPrefix() . 'extension_ignored_updates';
    }

    public static function favorites(): string
    {
        return Config::dbPrefix() . 'favorites';
    }

    public static function groupAccess(): string
    {
        return Config::dbPrefix() . 'group_access';
    }

    public static function groups(): string
    {
        return Config::dbPrefix() . 'groups';
    }

    public static function history(): string
    {
        return Config::dbPrefix() . 'history';
    }

    public static function historySummary(): string
    {
        return Config::dbPrefix() . 'history_summary';
    }

    public static function imageCategory(): string
    {
        return Config::dbPrefix() . 'image_category';
    }

    public static function imageFormat(): string
    {
        return Config::dbPrefix() . 'image_format';
    }

    public static function imageTag(): string
    {
        return Config::dbPrefix() . 'image_tag';
    }

    public static function images(): string
    {
        return Config::dbPrefix() . 'images';
    }

    public static function integrityIgnoredAnomalies(): string
    {
        return Config::dbPrefix() . 'integrity_ignored_anomalies';
    }

    public static function languages(): string
    {
        return Config::dbPrefix() . 'languages';
    }

    public static function lounge(): string
    {
        return Config::dbPrefix() . 'lounge';
    }

    public static function oldPermalinks(): string
    {
        return Config::dbPrefix() . 'old_permalinks';
    }

    public static function pluginMigrations(): string
    {
        return Config::dbPrefix() . 'plugin_migrations';
    }

    public static function plugins(): string
    {
        return Config::dbPrefix() . 'plugins';
    }

    public static function rate(): string
    {
        return Config::dbPrefix() . 'rate';
    }

    public static function search(): string
    {
        return Config::dbPrefix() . 'search';
    }

    public static function searchFilterView(): string
    {
        return Config::dbPrefix() . 'search_filter_view';
    }

    public static function sessions(): string
    {
        return Config::dbPrefix() . 'sessions';
    }

    public static function sites(): string
    {
        return Config::dbPrefix() . 'sites';
    }

    public static function tags(): string
    {
        return Config::dbPrefix() . 'tags';
    }

    public static function themes(): string
    {
        return Config::dbPrefix() . 'themes';
    }

    public static function upgrade(): string
    {
        return Config::dbPrefix() . 'upgrade';
    }

    public static function userAccess(): string
    {
        return Config::dbPrefix() . 'user_access';
    }

    public static function userAuthKeys(): string
    {
        return Config::dbPrefix() . 'user_auth_keys';
    }

    public static function userCache(): string
    {
        return Config::dbPrefix() . 'user_cache';
    }

    public static function userCacheCategories(): string
    {
        return Config::dbPrefix() . 'user_cache_categories';
    }

    public static function userFailedLogins(): string
    {
        return Config::dbPrefix() . 'user_failed_logins';
    }

    public static function userFeed(): string
    {
        return Config::dbPrefix() . 'user_feed';
    }

    public static function userGroup(): string
    {
        return Config::dbPrefix() . 'user_group';
    }

    public static function userInfos(): string
    {
        return Config::dbPrefix() . 'user_infos';
    }

    public static function userMailNotification(): string
    {
        return Config::dbPrefix() . 'user_mail_notification';
    }

    public static function users(): string
    {
        return Config::dbPrefix() . 'users';
    }
}
