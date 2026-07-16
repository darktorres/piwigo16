<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance;

use Piwigo\Db\Tables;
use Piwigo\Template\Template;

/**
 * Ported from admin/include/functions.php's fs_quick_check()/
 * images_integrity() (P23 batch 8d). Narrow "check X, fix Y" utilities,
 * distinct from the much larger, general anomaly framework in
 * Piwigo\Admin\Integrity\check_integrity (add_anomaly()/display()/
 * maintenance()) -- not the same concern, deliberately not merged in and
 * deliberately named more specifically to avoid confusion with it.
 */
final class FilesystemIntegrityChecker
{
    /**
     * Displays a header warning if we find missing photos on a random sample.
     *
     * @since 13.4.0
     */
    public static function fsQuickCheck(): void
    {
        /**
         * @var array<string, mixed> $page
         * @var array<string, mixed> $conf
         */
        global $page, $conf;

        $fs_quick_check_period = $conf['fs_quick_check_period'];
        if (is_numeric($fs_quick_check_period) && (int) $fs_quick_check_period === 0) {
            return;
        }

        if (isset($page[__FUNCTION__ . '_already_called'])) {
            return;
        }

        $page[__FUNCTION__ . '_already_called'] = true;
        conf_update_param('fs_quick_check_last_check', date('c'));

        $query = '
SELECT
    id
  FROM ' . Tables::images() . '
  WHERE date_available < \'2022-12-08 00:00:00\'
    AND path LIKE \'./upload/%\'
  LIMIT 5000
;';
        $issue1827_ids = query2array($query, null, 'id');
        shuffle($issue1827_ids);
        $issue1827_ids = array_slice($issue1827_ids, 0, 50);

        $query = '
SELECT
    id
  FROM ' . Tables::images() . '
  LIMIT 5000
;';
        $random_image_ids = query2array($query, null, 'id');
        shuffle($random_image_ids);
        $random_image_ids = array_slice($random_image_ids, 0, 50);

        $fs_quick_check_ids = array_unique(array_merge($issue1827_ids, $random_image_ids));

        if (count($fs_quick_check_ids) < 1) {
            return;
        }

        $query = '
SELECT
    id,
    path
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', $fs_quick_check_ids) . ')
;';
        $fsqc_paths = query2array($query, 'id', 'path');

        foreach ($fsqc_paths as $id => $path) {
            // path is a NOT NULL column in the images table.
            assert(is_string($path));
            if (! file_exists($path)) {
                /** @var Template $template */
                global $template;

                $template->assign(
                    'header_msgs',
                    [
                        l10n('Some photos are missing from your file system. Details provided by plugin Check Uploads'),
                    ]
                );

                return;
            }
        }

        // search for duplicate paths
        $query = '
SELECT
    path
  FROM ' . Tables::images() . '
  GROUP BY path
  HAVING COUNT(*) > 1
;';
        $duplicate_paths = query2array($query);

        if (count($duplicate_paths) > 0) {
            /** @var Template $template */
            global $template;

            $template->assign(
                'header_msgs',
                [
                    l10n('We have found %d duplicate paths. Details provided by plugin Check Uploads', count($duplicate_paths)),
                ]
            );

            return;
        }
    }

    public static function imagesIntegrity(): void
    {
        $query = '
SELECT
    image_id
  FROM ' . Tables::imageCategory() . '
    LEFT JOIN ' . Tables::images() . ' ON id = image_id
  WHERE id IS NULL
;';
        $orphan_image_ids = query2array($query, null, 'image_id');

        if (count($orphan_image_ids) > 0) {
            $query = '
DELETE
  FROM ' . Tables::imageCategory() . '
  WHERE image_id IN (' . implode(',', $orphan_image_ids) . ')
;';
            pwg_query($query);
        }
    }
}
