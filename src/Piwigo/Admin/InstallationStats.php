<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Db\DbConnection;
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
        $conn = DbConnection::build();

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::images() . '
;';
        $row = $conn->fetchNumeric($query);
        $stats['nb_photos'] = $row !== false ? $row[0] : 0;

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::categories() . '
;';
        $row = $conn->fetchNumeric($query);
        $stats['nb_categories'] = $row !== false ? $row[0] : 0;

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::tags() . '
;';
        $row = $conn->fetchNumeric($query);
        $stats['nb_tags'] = $row !== false ? $row[0] : 0;

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::imageTag() . '
;';
        $row = $conn->fetchNumeric($query);
        $stats['nb_image_tag'] = $row !== false ? $row[0] : 0;

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::users() . '
;';
        $row = $conn->fetchNumeric($query);
        $stats['nb_users'] = $row !== false ? $row[0] : 0;

        $query = '
SELECT
    COUNT(*)
  FROM ' . Tables::userInfos() . '
  WHERE status IN (\'webmaster\', \'admin\')
;';
        $row = $conn->fetchNumeric($query);
        $stats['nb_admins'] = $row !== false ? $row[0] : 0;

        $query = '
SELECT COUNT(*)
  FROM `' . Tables::groups() . '`
;';
        $row = $conn->fetchNumeric($query);
        $stats['nb_groups'] = $row !== false ? $row[0] : 0;

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::rate() . '
;';
        $row = $conn->fetchNumeric($query);
        $stats['nb_rates'] = $row !== false ? $row[0] : 0;

        $query = '
SELECT
    SUM(nb_pages)
  FROM ' . Tables::historySummary() . '
  WHERE month IS NULL
;';
        $row = $conn->fetchNumeric($query);
        $stats['nb_views'] = $row !== false ? $row[0] : 0;

        $query = '
SELECT
    SUM(filesize)
  FROM ' . Tables::images() . '
;';
        $row = $conn->fetchNumeric($query);
        $stats['disk_usage'] = $row !== false ? $row[0] : 0;

        $query = '
SELECT
    COUNT(*),
    SUM(filesize)
  FROM ' . Tables::imageFormat() . '
;';
        $row = $conn->fetchNumeric($query);
        if ($row !== false) {
            [$stats['nb_formats'], $stats['formats_disk_usage']] = $row;
        } else {
            $stats['nb_formats'] = 0;
            $stats['formats_disk_usage'] = 0;
        }

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
        $conn = DbConnection::build();

        $query = '
SELECT
    registration_date
  FROM ' . Tables::userInfos() . '
  WHERE user_id = 2
;';
        $users = $conn->fetchAllAssociative($query);
        if (count($users) > 0) {
            $candidate = $users[0]['registration_date'];
        }

        if (in_array($candidate, [null, false, 0, '0', '', []], true) or ! is_string($candidate) or strtotime($candidate) < strtotime($piwigo_origins)) {
            $query = '
SELECT
    MIN(registration_date) AS min_registration_date
  FROM ' . Tables::userInfos() . '
  WHERE registration_date > \'' . $piwigo_origins . '\'
;';
            $users = $conn->fetchAllAssociative($query);
            if (count($users) > 0) {
                $candidate = $users[0]['min_registration_date'];
            }
        }

        if (in_array($candidate, [null, false, 0, '0', '', []], true) or ! is_string($candidate) or strtotime($candidate) < strtotime($piwigo_origins)) {
            // let's find another candidate
            $query = '
SELECT
    date_available
  FROM ' . Tables::images() . '
  ORDER BY id ASC
  LIMIT 1
;';
            $images = $conn->fetchAllAssociative($query);
            if (count($images) > 0) {
                $candidate = $images[0]['date_available'];
            }
        }

        return $candidate;
    }
}
