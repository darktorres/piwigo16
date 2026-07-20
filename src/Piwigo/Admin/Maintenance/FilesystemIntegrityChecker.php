<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance;

use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

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
     * Per-request run-once guard for fsQuickCheck() -- replaces the
     * former `$page[__FUNCTION__ . '_already_called']` global (Phase 2
     * global-residual sweep). Genuinely load-bearing, not dead: confirmed
     * by reading both call chains, not assumed -- admin.php's own
     * AdminShell::run() calls fsQuickCheck() directly, then dispatches to
     * a sub-controller (e.g. Controller\Admin\IntroSubController) that
     * calls it again within that same dispatched request.
     */
    private static bool $fsQuickCheckDone = false;

    /**
     * Displays a header warning if we find missing photos on a random sample.
     *
     * @since 13.4.0
     */
    public static function fsQuickCheck(): void
    {
        $fs_quick_check_period = \Piwigo\Config\Config::fsQuickCheckPeriod();
        if (is_numeric($fs_quick_check_period) && $fs_quick_check_period === 0) {
            return;
        }

        if (self::$fsQuickCheckDone) {
            return;
        }

        self::$fsQuickCheckDone = true;
        \Piwigo\Config\ConfigDb::confUpdateParam('fs_quick_check_last_check', date('c'));

        $conn = DbConnection::build();

        $query = '
SELECT
    id
  FROM ' . Tables::images() . '
  WHERE date_available < \'2022-12-08 00:00:00\'
    AND path LIKE \'./upload/%\'
  LIMIT 5000
;';
        $issue1827_ids = array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            array_column($conn->fetchAllAssociative($query), 'id')
        );
        shuffle($issue1827_ids);
        $issue1827_ids = array_slice($issue1827_ids, 0, 50);

        $query = '
SELECT
    id
  FROM ' . Tables::images() . '
  LIMIT 5000
;';
        $random_image_ids = array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            array_column($conn->fetchAllAssociative($query), 'id')
        );
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
        $fsqc_paths = array_column($conn->fetchAllAssociative($query), 'path', 'id');

        foreach ($fsqc_paths as $id => $path) {
            // path is a NOT NULL column in the images table.
            assert(is_string($path));
            if (! file_exists($path)) {
                $template = \Piwigo\Template\CurrentTemplate::get();

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
        $duplicate_paths = $conn->fetchAllAssociative($query);

        if (count($duplicate_paths) > 0) {
            $template = \Piwigo\Template\CurrentTemplate::get();

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
        $conn = DbConnection::build();

        $query = '
SELECT
    image_id
  FROM ' . Tables::imageCategory() . '
    LEFT JOIN ' . Tables::images() . ' ON id = image_id
  WHERE id IS NULL
;';
        $orphan_image_ids = array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            array_column($conn->fetchAllAssociative($query), 'image_id')
        );

        if (count($orphan_image_ids) > 0) {
            $query = '
DELETE
  FROM ' . Tables::imageCategory() . '
  WHERE image_id IN (' . implode(',', $orphan_image_ids) . ')
;';
            $conn->executeStatement($query);
        }
    }

    /**
     * Test-only -- restricted to tests/ by an arch test, mirroring the
     * equivalent guard on SessionService's/Config's own reset() methods.
     */
    public static function reset(): void
    {
        self::$fsQuickCheckDone = false;
    }
}
