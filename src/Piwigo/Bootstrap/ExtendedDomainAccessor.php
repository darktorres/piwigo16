<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Activity\ActivityService;
use Piwigo\Comment\CommentService;
use Piwigo\Core\Kernel;
use Piwigo\Metadata\MetadataService;
use Piwigo\Rate\RateService;
use Piwigo\Search\SearchService;

/**
 * DI-migration follow-on to gap-closure Stage 4: typed accessors to
 * container-resolved L2bExtendedDomain services, for L4Integration callers
 * (Admin/Command/Controller/Ws) that can't call `Kernel::container()`
 * directly -- see `Bootstrap\CoreDomainAccessor`'s own docblock for the
 * full rationale (same shape, different deptrac layer).
 */
final class ExtendedDomainAccessor
{
    public static function activityService(): ActivityService
    {
        $service = Kernel::container()->get(ActivityService::class);
        if (! $service instanceof ActivityService) {
            throw new \LogicException('Container returned an unexpected type for ' . ActivityService::class);
        }
        return $service;
    }

    public static function commentService(): CommentService
    {
        $service = Kernel::container()->get(CommentService::class);
        if (! $service instanceof CommentService) {
            throw new \LogicException('Container returned an unexpected type for ' . CommentService::class);
        }
        return $service;
    }

    public static function searchService(): SearchService
    {
        $service = Kernel::container()->get(SearchService::class);
        if (! $service instanceof SearchService) {
            throw new \LogicException('Container returned an unexpected type for ' . SearchService::class);
        }
        return $service;
    }

    public static function metadataService(): MetadataService
    {
        $service = Kernel::container()->get(MetadataService::class);
        if (! $service instanceof MetadataService) {
            throw new \LogicException('Container returned an unexpected type for ' . MetadataService::class);
        }
        return $service;
    }

    public static function rateService(): RateService
    {
        $service = Kernel::container()->get(RateService::class);
        if (! $service instanceof RateService) {
            throw new \LogicException('Container returned an unexpected type for ' . RateService::class);
        }
        return $service;
    }
}
