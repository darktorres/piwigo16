<?php

declare(strict_types=1);

namespace Piwigo\Activity\Projection;

use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;

/**
 * {@see \Piwigo\Activity\ActivityRepository::findPaginated()}'s own row
 * shape -- every {@see \Piwigo\Activity\ActivityEntity} column except its
 * auto-increment `activityId` -- {@see \Piwigo\Ws\Core::getActivityList()}'s
 * real (and only) consumer, its `history.log`/`activity.search`-style WS
 * listing.
 */
final readonly class PaginatedActivityRow
{
    /**
     * @param array<string, mixed>|null $details
     */
    public function __construct(
        public ?UserId $performedBy,
        public string $object,
        public int $objectId,
        public string $action,
        public string $sessionIdx,
        public ?IpAddress $ipAddress,
        public SqlDateTime $occuredOn,
        public ?array $details,
        public ?string $userAgent,
    ) {}
}
