<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use LogicException;
use Piwigo\Activity\ActivityService;
use Piwigo\Core\Kernel;
use Piwigo\Metadata\MetadataService;

/**
 * Typed accessors to container-resolved L2bExtendedDomain services, for
 * L4Integration callers (Admin/Command/Controller/Ws) that can't call
 * `Kernel::container()` directly -- see `Bootstrap\CoreDomainAccessor`'s
 * own docblock for the full rationale (same shape, different deptrac
 * layer).
 *
 * activityService()'s only callers are Admin/Install/{InstallService,
 * InstallWizard}.php's own static-context install flow; metadataService()'s
 * only caller is config/messenger.php (outside `src/Piwigo`, and
 * deliberately outside the `Kernel::container()` arch-test boundary too,
 * per that file's own docblock).
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
