<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Piwigo\Config\Config;

final class Tables
{
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
    public static function integrityIgnoredAnomalies(): string
    {
        return Config::dbPrefix() . 'integrity_ignored_anomalies';
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
        $custom = Config::usersTable();
        return $custom ?? Config::dbPrefix() . 'users';
    }
    public static function activity(): string
    {
        return Config::dbPrefix() . 'activity';
    }
    public static function caddie(): string
    {
        return Config::dbPrefix() . 'caddie';
    }
}
