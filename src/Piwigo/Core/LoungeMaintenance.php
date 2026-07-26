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
 *
 * Legacy Coupling Retirement: DI+DBAL migration Phase 1e -- `Piwigo\Db`
 * is the same L1Infrastructure layer as this class, so `DbConnection`
 * (constructed inline; static method, no instance state) is a legal,
 * same-layer dependency.
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

        if (! \Piwigo\Config\CurrentConfig::loungeActive()) {
            return false;
        }

        $requestMethod = Request\LoungeMaintenanceRequest::fromGlobals()->requestMethod;
        if (in_array($requestMethod, ['pwg.images.upload', 'pwg.images.uploadAsync'], true)) {
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
        $voyagers = \Piwigo\Db\DbConnection::build()->fetchAllAssociative($query);
        if (count($voyagers) === 0) {
            return false;
        }

        $voyager = $voyagers[0];
        $voyager_dbnow = $voyager['dbnow'];
        $voyager_date_available = $voyager['date_available'];
        $dbnow = strtotime(is_scalar($voyager_dbnow) ? (string) $voyager_dbnow : '');
        $date_available = strtotime(is_scalar($voyager_date_available) ? (string) $voyager_date_available : '');
        // dbnow/date_available come straight from NOW() and a required,
        // populated lounge-table column -- both are always well-formed
        // MySQL datetimes in practice; the false fallback (age 0) is a
        // defensive no-op, not an expected real path.
        $age = ($dbnow !== false ? $dbnow : 0) - ($date_available !== false ? $date_available : 0);

        $lounge_max_duration = \Piwigo\Config\CurrentConfig::loungeMaxDuration();

        return $age > $lounge_max_duration;
    }
}
