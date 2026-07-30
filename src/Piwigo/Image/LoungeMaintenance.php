<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;

/**
 * Relocated from Piwigo\Core (P23 batch 8d's original placement): its own
 * query touches `lounge`/`images`, an Image-domain concern
 * ImageRepository::findLoungeRows() already covers the "list every lounge
 * row" half of -- staying in Core\ (L1Infrastructure) would have meant
 * either duplicating that query outside its own repository, or reaching
 * "up" from L1Infrastructure into Image\ImageRepository (L2aCoreDomain),
 * a deptrac violation deptrac only allows in the other direction. Moved
 * here instead: needsEmptying() now calls
 * ImageRepository::findOldestLoungeAgeInfo() (Image, same layer), and the
 * real domain action ImageService::emptyLounge() this class's own
 * `needsEmptying()` gates (via include/common.inc.php's own
 * `needsEmptying()` caller, isn't deptrac-scanned) is now a same-layer
 * (Image -> Image) call instead of a cross-layer one.
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

        $requestMethod = Request\EmptyLoungeRequest::fromGlobals()->requestMethod;
        if (in_array($requestMethod, ['pwg.images.upload', 'pwg.images.uploadAsync'], true)) {
            return false;
        }

        // is the oldest photo in the lounge older than lounge maximum waiting time?
        $ageInfo = EntityManagerFactory::build(DbConnection::build())->getRepository(ImageEntity::class)
            ->findOldestLoungeAgeInfo();
        if ($ageInfo === null) {
            return false;
        }

        $dbnow = strtotime($ageInfo['dbNow']);
        $date_available = strtotime($ageInfo['dateAvailable']);
        // dbnow/date_available come straight from NOW() and a required,
        // populated lounge-table column -- both are always well-formed
        // MySQL datetimes in practice; the false fallback (age 0) is a
        // defensive no-op, not an expected real path.
        $age = ($dbnow !== false ? $dbnow : 0) - ($date_available !== false ? $date_available : 0);

        $lounge_max_duration = \Piwigo\Config\CurrentConfig::loungeMaxDuration();

        return $age > $lounge_max_duration;
    }
}
