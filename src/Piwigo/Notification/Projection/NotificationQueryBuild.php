<?php

declare(strict_types=1);

namespace Piwigo\Notification\Projection;

use Doctrine\ORM\QueryBuilder;

/**
 * {@see \Piwigo\Notification\NotificationRepository::buildQuery()}'s own
 * result -- the partially-built query plus the DQL field expression its 2
 * real callers select/count by.
 */
final readonly class NotificationQueryBuild
{
    public function __construct(
        public QueryBuilder $queryBuilder,
        public string $fieldId,
    ) {}
}
