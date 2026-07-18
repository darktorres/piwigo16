<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Db\Tables;

/**
 * P23 batch 8d: relocated from include/functions.inc.php. Only checks the
 * condition -- the real domain action (Piwigo\Image\ImageService::
 * emptyLounge(), Elements/photos sub-batch) is L2aCoreDomain, and this
 * class is L1Infrastructure (deptrac only allows L1Infrastructure ->
 * L0Data), so it can't call it directly. include/common.inc.php's own
 * `needsEmptying()` caller (its only real caller, isn't deptrac-scanned)
 * triggers emptyLounge() itself once this returns true.
 */
final class LoungeMaintenance
{
    /**
     * Checks if the lounge needs to be emptied automatically.
     *
     * @since 12
     */
    public static function needsEmptying(): bool
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        if (! \Piwigo\Config\Config::has('lounge_active') || ! \Piwigo\Config\Config::loungeActive()) {
            return false;
        }

        if (isset($_REQUEST['method']) && in_array($_REQUEST['method'], ['pwg.images.upload', 'pwg.images.uploadAsync'], true)) {
            return false;
        }

        // is the oldest photo in the lounge older than lounge maximum waiting time?
        $query = '
SELECT
    image_id,
    date_available,
    NOW() AS dbnow
  FROM ' . Tables::lounge() . '
    JOIN ' . Tables::images() . ' ON image_id = id
  ORDER BY image_id ASC
  LIMIT 1
;';
        $voyagers = \Piwigo\Db\MysqliDb::query2Array($query);
        if (count($voyagers) === 0) {
            return false;
        }

        $voyager = $voyagers[0];
        $dbnow = strtotime((string) $voyager['dbnow']);
        $date_available = strtotime((string) $voyager['date_available']);
        // dbnow/date_available come straight from NOW() and a required,
        // populated lounge-table column -- both are always well-formed
        // MySQL datetimes in practice; the false fallback (age 0) is a
        // defensive no-op, not an expected real path.
        $age = ($dbnow !== false ? $dbnow : 0) - ($date_available !== false ? $date_available : 0);

        $lounge_max_duration = \Piwigo\Config\Config::loungeMaxDuration();

        return $age > $lounge_max_duration;
    }
}
