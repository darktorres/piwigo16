<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Comment\CommentService;
use Piwigo\Core\Kernel;
use Piwigo\History\HistoryService;
use Piwigo\Metadata\MetadataService;
use Piwigo\Notification\NotificationByMailService;
use Piwigo\Notification\NotificationService;
use Piwigo\Permalink\PermalinkService;
use Piwigo\Rate\RateService;
use Piwigo\Search\SearchFilterRenderer;
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

    public static function searchFilterRenderer(): SearchFilterRenderer
    {
        $service = Kernel::container()->get(SearchFilterRenderer::class);
        if (! $service instanceof SearchFilterRenderer) {
            throw new \LogicException('Container returned an unexpected type for ' . SearchFilterRenderer::class);
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

    public static function historyService(): HistoryService
    {
        $service = Kernel::container()->get(HistoryService::class);
        if (! $service instanceof HistoryService) {
            throw new \LogicException('Container returned an unexpected type for ' . HistoryService::class);
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

    public static function notificationService(): NotificationService
    {
        $service = Kernel::container()->get(NotificationService::class);
        if (! $service instanceof NotificationService) {
            throw new \LogicException('Container returned an unexpected type for ' . NotificationService::class);
        }
        return $service;
    }

    public static function notificationByMailService(): NotificationByMailService
    {
        $service = Kernel::container()->get(NotificationByMailService::class);
        if (! $service instanceof NotificationByMailService) {
            throw new \LogicException('Container returned an unexpected type for ' . NotificationByMailService::class);
        }
        return $service;
    }

    public static function permalinkService(): PermalinkService
    {
        $service = Kernel::container()->get(PermalinkService::class);
        if (! $service instanceof PermalinkService) {
            throw new \LogicException('Container returned an unexpected type for ' . PermalinkService::class);
        }
        return $service;
    }
}
