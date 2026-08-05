<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use LogicException;
use Piwigo\Activity\ActivityService;
use Piwigo\Core\Kernel;
use Piwigo\Metadata\MetadataService;

/**
 * DI-migration follow-on to gap-closure Stage 4: typed accessors to
 * container-resolved L2bExtendedDomain services, for L4Integration callers
 * (Admin/Command/Controller/Ws) that can't call `Kernel::container()`
 * directly -- see `Bootstrap\CoreDomainAccessor`'s own docblock for the
 * full rationale (same shape, different deptrac layer).
 *
 * Singleton/service-locator elimination campaign, Phase 10: PwgImages's
 * own conversion (the last Ws/Pwg* class using this accessor) emptied
 * commentService()/searchService()/rateService() -- deleted along with
 * their dedicated tests. activityService() and metadataService() both
 * stay: activityService()'s only remaining callers are Admin/Install/
 * {InstallService,InstallWizard}.php's own genuinely-static-context
 * install flow; metadataService()'s only remaining caller is config/
 * messenger.php (outside `src/Piwigo`, and deliberately outside the
 * `Kernel::container()` arch-test boundary too, per that file's own
 * docblock) -- see CoreDomainAccessor's own matching note for
 * imageService(), the identical gap, found the same way.
 */
final class ExtendedDomainAccessor
{
    public static function activityService(): ActivityService
    {
        $service = Kernel::container()->get(ActivityService::class);
        if (! $service instanceof ActivityService) {
            throw new LogicException('Container returned an unexpected type for ' . ActivityService::class);
        }
        return $service;
    }

    public static function metadataService(): MetadataService
    {
        $service = Kernel::container()->get(MetadataService::class);
        if (! $service instanceof MetadataService) {
            throw new LogicException('Container returned an unexpected type for ' . MetadataService::class);
        }
        return $service;
    }
}
