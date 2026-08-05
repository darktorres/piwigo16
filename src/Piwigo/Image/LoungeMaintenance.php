<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Env;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Image\Request\EmptyLoungeRequest;

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
 *
 * Singleton/service-locator elimination campaign, Phase 9: `needsEmptying()`
 * is a purely static method with no instance/constructor at all (same
 * shape as `Core\FilesystemHelper`) -- there is no `$this` to receive
 * `CurrentConfig` via constructor injection through, so it reads via the
 * `CurrentConfig::current()` transitional bridge instead, matching
 * FilesystemHelper's own established "no wrapper needed" precedent for a
 * stateless static utility.
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

        if (! CurrentConfig::current()->loungeActive()) {
            return false;
        }

        $requestMethod = EmptyLoungeRequest::fromGlobals()->requestMethod;
        if (in_array($requestMethod, ['pwg.images.upload', 'pwg.images.uploadAsync'], true)) {
            return false;
        }

        // is the oldest photo in the lounge older than lounge maximum waiting time?
        $dateAvailable = EntityManagerFactory::build(DbConnection::build())->getRepository(ImageEntity::class)
            ->findOldestLoungeAgeInfo();
        if ($dateAvailable === null) {
            return false;
        }

        $dbnow = Env::now()->getTimestamp();
        $date_available = strtotime($dateAvailable);
        // date_available comes straight from a required, populated
        // lounge-table column -- always a well-formed MySQL datetime in
        // practice; the false fallback (age 0) is a defensive no-op, not
        // an expected real path.
        $age = $dbnow - ($date_available !== false ? $date_available : 0);

        $lounge_max_duration = CurrentConfig::current()->loungeMaxDuration();

        return $age > $lounge_max_duration;
    }
}
