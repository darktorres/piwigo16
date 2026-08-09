<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Env;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Image\Request\EmptyLoungeRequest;

/**
 * Lives in the Image domain (not Core\) because its query touches
 * `lounge`/`images` -- the same domain `ImageRepository::findLoungeRows()`
 * already covers. `needsEmptying()` calls
 * `ImageRepository::findOldestLoungeAgeInfo()` (same layer), and the
 * domain action `ImageService::emptyLounge()` this class's own
 * `needsEmptying()` gates is a same-layer (Image -> Image) call.
 *
 * `needsEmptying()` is a purely static method with no instance/
 * constructor at all (same shape as `Core\FilesystemHelper`), so there is
 * no `$this` to receive `CurrentConfig` via constructor injection through
 * -- it takes `CurrentConfig` as an explicit parameter instead. Its one
 * real caller, Bootstrap\RequestBootstrap.php, already has one in scope
 * via its own self::currentConfig() accessor, right next to this
 * method's own call site.
 */
final class LoungeMaintenance
{
    /**
     * Checks if the lounge needs to be emptied automatically.
     */
    public static function needsEmptying(CurrentConfig $currentConfig): bool
    {

        if (! $currentConfig->loungeActive) {
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

        $lounge_max_duration = $currentConfig->loungeMaxDuration;

        return $age > $lounge_max_duration;
    }
}
