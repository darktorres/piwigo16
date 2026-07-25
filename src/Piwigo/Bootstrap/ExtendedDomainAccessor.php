<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Comment\CommentService;
use Piwigo\Core\Kernel;

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
}
