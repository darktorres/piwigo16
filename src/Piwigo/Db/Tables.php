<?php

declare(strict_types=1);

namespace Piwigo\Db;

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
        return DbCredentials::current()->prefix . 'activity';
    }

    public static function auditLog(): string
    {
        return DbCredentials::current()->prefix . 'audit_log';
    }

    public static function caddie(): string
    {
        return DbCredentials::current()->prefix . 'caddie';
    }

    public static function categories(): string
    {
        return DbCredentials::current()->prefix . 'categories';
    }

    public static function comments(): string
    {
        return DbCredentials::current()->prefix . 'comments';
    }

    public static function config(): string
    {
        return DbCredentials::current()->prefix . 'config';
    }

    public static function derivativeSettings(): string
    {
        return DbCredentials::current()->prefix . 'derivative_settings';
    }

    public static function derivativeSize(): string
    {
        return DbCredentials::current()->prefix . 'derivative_size';
    }

    public static function extensionIgnoredUpdates(): string
    {
        return DbCredentials::current()->prefix . 'extension_ignored_updates';
    }

    public static function favorites(): string
    {
        return DbCredentials::current()->prefix . 'favorites';
    }

    public static function groupAccess(): string
    {
        return DbCredentials::current()->prefix . 'group_access';
    }

    public static function groups(): string
    {
        return DbCredentials::current()->prefix . 'groups';
    }

    public static function history(): string
    {
        return DbCredentials::current()->prefix . 'history';
    }

    public static function historySummary(): string
    {
        return DbCredentials::current()->prefix . 'history_summary';
    }

    public static function imageCategory(): string
    {
        return DbCredentials::current()->prefix . 'image_category';
    }

    public static function imageFormat(): string
    {
        return DbCredentials::current()->prefix . 'image_format';
    }

    public static function imageTag(): string
    {
        return DbCredentials::current()->prefix . 'image_tag';
    }

    public static function images(): string
    {
        return DbCredentials::current()->prefix . 'images';
    }

    public static function integrityIgnoredAnomalies(): string
    {
        return DbCredentials::current()->prefix . 'integrity_ignored_anomalies';
    }

    public static function languages(): string
    {
        return DbCredentials::current()->prefix . 'languages';
    }

    public static function lounge(): string
    {
        return DbCredentials::current()->prefix . 'lounge';
    }

    public static function oldPermalinks(): string
    {
        return DbCredentials::current()->prefix . 'old_permalinks';
    }

    public static function pluginMigrations(): string
    {
        return DbCredentials::current()->prefix . 'plugin_migrations';
    }

    public static function plugins(): string
    {
        return DbCredentials::current()->prefix . 'plugins';
    }

    public static function rate(): string
    {
        return DbCredentials::current()->prefix . 'rate';
    }

    public static function search(): string
    {
        return DbCredentials::current()->prefix . 'search';
    }

    public static function searchFilterView(): string
    {
        return DbCredentials::current()->prefix . 'search_filter_view';
    }

    public static function sessions(): string
    {
        return DbCredentials::current()->prefix . 'sessions';
    }

    public static function sites(): string
    {
        return DbCredentials::current()->prefix . 'sites';
    }

    public static function tags(): string
    {
        return DbCredentials::current()->prefix . 'tags';
    }

    public static function themes(): string
    {
        return DbCredentials::current()->prefix . 'themes';
    }

    public static function userAccess(): string
    {
        return DbCredentials::current()->prefix . 'user_access';
    }

    public static function userAuthKeys(): string
    {
        return DbCredentials::current()->prefix . 'user_auth_keys';
    }

    public static function userCache(): string
    {
        return DbCredentials::current()->prefix . 'user_cache';
    }

    public static function userCacheCategories(): string
    {
        return DbCredentials::current()->prefix . 'user_cache_categories';
    }

    public static function userFailedLogins(): string
    {
        return DbCredentials::current()->prefix . 'user_failed_logins';
    }

    public static function userFeed(): string
    {
        return DbCredentials::current()->prefix . 'user_feed';
    }

    public static function userGroup(): string
    {
        return DbCredentials::current()->prefix . 'user_group';
    }

    public static function userInfos(): string
    {
        return DbCredentials::current()->prefix . 'user_infos';
    }

    public static function userMailNotification(): string
    {
        return DbCredentials::current()->prefix . 'user_mail_notification';
    }

    public static function users(): string
    {
        return DbCredentials::current()->prefix . 'users';
    }
}
