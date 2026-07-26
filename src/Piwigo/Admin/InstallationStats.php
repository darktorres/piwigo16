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
     * @return array{nb_photos: int, nb_categories: int, nb_tags: int,
     *   nb_image_tag: int, nb_users: int, nb_admins: int, nb_groups: int,
     *   nb_rates: int, nb_views: int, disk_usage: int, nb_formats: int,
     *   formats_disk_usage: int}
     */
    public static function getGeneralStatistics(): array
    {
        $conn = DbConnection::build();

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::images() . '
;';
        $row = $conn->fetchNumeric($query);
        $nb_photos = ($row !== false && is_numeric($row[0])) ? (int) $row[0] : 0;

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::categories() . '
;';
        $row = $conn->fetchNumeric($query);
        $nb_categories = ($row !== false && is_numeric($row[0])) ? (int) $row[0] : 0;

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::tags() . '
;';
        $row = $conn->fetchNumeric($query);
        $nb_tags = ($row !== false && is_numeric($row[0])) ? (int) $row[0] : 0;

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::imageTag() . '
;';
        $row = $conn->fetchNumeric($query);
        $nb_image_tag = ($row !== false && is_numeric($row[0])) ? (int) $row[0] : 0;

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::users() . '
;';
        $row = $conn->fetchNumeric($query);
        $nb_users = ($row !== false && is_numeric($row[0])) ? (int) $row[0] : 0;

        $query = '
SELECT
    COUNT(*)
  FROM ' . Tables::userInfos() . '
  WHERE status IN (\'webmaster\', \'admin\')
;';
        $row = $conn->fetchNumeric($query);
        $nb_admins = ($row !== false && is_numeric($row[0])) ? (int) $row[0] : 0;

        $query = '
SELECT COUNT(*)
  FROM `' . Tables::groups() . '`
;';
        $row = $conn->fetchNumeric($query);
        $nb_groups = ($row !== false && is_numeric($row[0])) ? (int) $row[0] : 0;

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::rate() . '
;';
        $row = $conn->fetchNumeric($query);
        $nb_rates = ($row !== false && is_numeric($row[0])) ? (int) $row[0] : 0;

        // SUM() returns NULL (not '0') when the table has no matching rows.
        $query = '
SELECT
    SUM(nb_pages)
  FROM ' . Tables::historySummary() . '
  WHERE month IS NULL
;';
        $row = $conn->fetchNumeric($query);
        $nb_views = ($row !== false && is_numeric($row[0])) ? (int) $row[0] : 0;

        $query = '
SELECT
    SUM(filesize)
  FROM ' . Tables::images() . '
;';
        $row = $conn->fetchNumeric($query);
        $images_disk_usage = ($row !== false && is_numeric($row[0])) ? (int) $row[0] : 0;

        $query = '
SELECT
    COUNT(*),
    SUM(filesize)
  FROM ' . Tables::imageFormat() . '
;';
        $row = $conn->fetchNumeric($query);
        $nb_formats = ($row !== false && is_numeric($row[0] ?? null)) ? (int) $row[0] : 0;
        $formats_disk_usage = ($row !== false && is_numeric($row[1] ?? null)) ? (int) $row[1] : 0;

        return [
            'nb_photos' => $nb_photos,
            'nb_categories' => $nb_categories,
            'nb_tags' => $nb_tags,
            'nb_image_tag' => $nb_image_tag,
            'nb_users' => $nb_users,
            'nb_admins' => $nb_admins,
            'nb_groups' => $nb_groups,
            'nb_rates' => $nb_rates,
            'nb_views' => $nb_views,
            'disk_usage' => $images_disk_usage + $formats_disk_usage,
            'nb_formats' => $nb_formats,
            'formats_disk_usage' => $formats_disk_usage,
        ];
    }

    /**
     * registration_date/min_registration_date/date_available are all
     * DATETIME columns, so every real $candidate assignment below is
     * string|null (this driver's fetch convention for a DATETIME column,
     * same as e.g. Category\Projection\Category::$lastmodified) --
     * narrowed to a real ?string at each assignment rather than trusting
     * that blindly.
     */
    public static function getInstallationDate(): ?string
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
            $registration_date = $users[0]['registration_date'];
            $candidate = is_string($registration_date) ? $registration_date : null;
        }

        if (in_array($candidate, [null, false, 0, '0', '', []], true) or strtotime($candidate) < strtotime($piwigo_origins)) {
            $query = '
SELECT
    MIN(registration_date) AS min_registration_date
  FROM ' . Tables::userInfos() . '
  WHERE registration_date > \'' . $piwigo_origins . '\'
;';
            $users = $conn->fetchAllAssociative($query);
            if (count($users) > 0) {
                $min_registration_date = $users[0]['min_registration_date'];
                $candidate = is_string($min_registration_date) ? $min_registration_date : null;
            }
        }

        if (in_array($candidate, [null, false, 0, '0', '', []], true) or strtotime($candidate) < strtotime($piwigo_origins)) {
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
                $date_available = $images[0]['date_available'];
                $candidate = is_string($date_available) ? $date_available : null;
            }
        }

        return $candidate;
    }
}
