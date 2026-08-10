<?php

declare(strict_types=1);

namespace Piwigo\Db;

/**
 * Table-name resolution for procedural code that writes raw SQL outside a
 * repository -- one static method per origin table's legacy TABLE_NAME
 * constant, plus a few for newer tables that never had legacy constants.
 *
 * ORM repositories never need this -- they address tables via entity
 * mapping (`#[ORM\Table(name: ...)]`) directly. Will be deleted alongside
 * the `AbstractRepository` DBAL shim once `include/` is removed.
 */
final class Tables
{
    public static function activity(): string
    {
        return 'activity';
    }

    public static function auditLog(): string
    {
        return 'audit_log';
    }

    public static function caddie(): string
    {
        return 'caddie';
    }

    public static function categories(): string
    {
        return 'categories';
    }

    public static function comments(): string
    {
        return 'comments';
    }

    public static function config(): string
    {
        return 'config';
    }

    public static function derivativeSettings(): string
    {
        return 'derivative_settings';
    }

    public static function derivativeSize(): string
    {
        return 'derivative_size';
    }

    public static function extensionIgnoredUpdates(): string
    {
        return 'extension_ignored_updates';
    }

    public static function favorites(): string
    {
        return 'favorites';
    }

    public static function groupAccess(): string
    {
        return 'group_access';
    }

    public static function groups(): string
    {
        return 'groups';
    }

    public static function history(): string
    {
        return 'history';
    }

    public static function historySummary(): string
    {
        return 'history_summary';
    }

    public static function imageCategory(): string
    {
        return 'image_category';
    }

    public static function imageFormat(): string
    {
        return 'image_format';
    }

    public static function imageTag(): string
    {
        return 'image_tag';
    }

    public static function images(): string
    {
        return 'images';
    }

    public static function integrityIgnoredAnomalies(): string
    {
        return 'integrity_ignored_anomalies';
    }

    public static function languages(): string
    {
        return 'languages';
    }

    public static function lounge(): string
    {
        return 'lounge';
    }

    public static function oldPermalinks(): string
    {
        return 'old_permalinks';
    }

    public static function pluginMigrations(): string
    {
        return 'plugin_migrations';
    }

    public static function plugins(): string
    {
        return 'plugins';
    }

    public static function rate(): string
    {
        return 'rate';
    }

    public static function search(): string
    {
        return 'search';
    }

    public static function searchFilterView(): string
    {
        return 'search_filter_view';
    }

    public static function sessions(): string
    {
        return 'sessions';
    }

    public static function sites(): string
    {
        return 'sites';
    }

    public static function tags(): string
    {
        return 'tags';
    }

    public static function themes(): string
    {
        return 'themes';
    }

    public static function userAccess(): string
    {
        return 'user_access';
    }

    public static function userAuthKeys(): string
    {
        return 'user_auth_keys';
    }

    public static function userFailedLogins(): string
    {
        return 'user_failed_logins';
    }

    public static function userFeed(): string
    {
        return 'user_feed';
    }

    public static function userGroup(): string
    {
        return 'user_group';
    }

    public static function userInfos(): string
    {
        return 'user_infos';
    }

    public static function userMailNotification(): string
    {
        return 'user_mail_notification';
    }

    public static function users(): string
    {
        return 'users';
    }
}
