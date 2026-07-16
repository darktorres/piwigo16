<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Db\Tables;

/**
 * Ported from admin/include/functions.php's get_pwg_general_statitics()/
 * get_installation_date() (P23 batch 8d). Lives under Admin\, not a
 * domain namespace -- its query set cuts across images/categories/tags/
 * users/groups/rates/history, no single domain owns it, same
 * "administrative machinery" shape as PiwigoInfosSender/PluginLoader in
 * this same namespace.
 */
final class InstallationStats
{
    /**
     * @return array<string, mixed>
     */
    public static function getGeneralStatistics(): array
    {
        $stats = [];

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::images() . '
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$stats['nb_photos']] = $row;

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::categories() . '
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$stats['nb_categories']] = $row;

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::tags() . '
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$stats['nb_tags']] = $row;

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::imageTag() . '
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$stats['nb_image_tag']] = $row;

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::users() . '
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$stats['nb_users']] = $row;

        $query = '
SELECT
    COUNT(*)
  FROM ' . Tables::userInfos() . '
  WHERE status IN (\'webmaster\', \'admin\')
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$stats['nb_admins']] = $row;

        $query = '
SELECT COUNT(*)
  FROM `' . Tables::groups() . '`
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$stats['nb_groups']] = $row;

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::rate() . '
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$stats['nb_rates']] = $row;

        $query = '
SELECT
    SUM(nb_pages)
  FROM ' . Tables::historySummary() . '
  WHERE month IS NULL
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$stats['nb_views']] = $row;

        $query = '
SELECT
    SUM(filesize)
  FROM ' . Tables::images() . '
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$stats['disk_usage']] = $row;

        $query = '
SELECT
    COUNT(*),
    SUM(filesize)
  FROM ' . Tables::imageFormat() . '
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$stats['nb_formats'], $stats['formats_disk_usage']] = $row;

        // SUM() returns NULL (not '0') when the table has no matching rows.
        $disk_usage = $stats['disk_usage'];
        $formats_disk_usage = $stats['formats_disk_usage'];
        $stats['disk_usage'] = (is_numeric($disk_usage) ? (int) $disk_usage : 0)
            + (is_numeric($formats_disk_usage) ? (int) $formats_disk_usage : 0);

        return $stats;
    }

    public static function getInstallationDate(): mixed
    {
        $candidate = null;

        // Piwigo first beta versions were created in septembre 2001, so it's not possible
        // to have an installation prior to this "origin of times"
        $piwigo_origins = '2001-09-01 00:00:00';

        $query = '
SELECT
    registration_date
  FROM ' . Tables::userInfos() . '
  WHERE user_id = 2
;';
        $users = query2array($query);
        if (count($users) > 0) {
            $candidate = $users[0]['registration_date'];
        }

        if (empty($candidate) or strtotime($candidate) < strtotime($piwigo_origins)) {
            $query = '
SELECT
    MIN(registration_date) AS min_registration_date
  FROM ' . Tables::userInfos() . '
  WHERE registration_date > \'' . $piwigo_origins . '\'
;';
            $users = query2array($query);
            if (count($users) > 0) {
                $candidate = $users[0]['min_registration_date'];
            }
        }

        if (empty($candidate) or strtotime($candidate) < strtotime($piwigo_origins)) {
            // let's find another candidate
            $query = '
SELECT
    date_available
  FROM ' . Tables::images() . '
  ORDER BY id ASC
  LIMIT 1
;';
            $images = query2array($query);
            if (count($images) > 0) {
                $candidate = $images[0]['date_available'];
            }
        }

        return $candidate;
    }
}
