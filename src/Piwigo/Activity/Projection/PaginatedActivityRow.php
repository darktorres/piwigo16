<?php

declare(strict_types=1);

namespace Piwigo\Activity\Projection;

use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\SqlDateTime;

/**
 * {@see \Piwigo\Activity\ActivityRepository::findPaginated()}'s own row
 * shape -- every {@see \Piwigo\Activity\ActivityEntity} column except its
 * auto-increment `activityId` -- {@see \Piwigo\Controller\Api\ActivityListController}'s
 * real (and only) consumer, its `GET /api/v1/activity` listing.
 *
 * `performedBy` stays plain `?int` even though
 * `ActivityEntity::$performedByUser` is a real association (`?UserEntity`)
 * -- deliberately breaking the VO-propagation convention every other
 * Projection in `0.3` follows for its own touched columns: this DTO is
 * `GetListHandler`'s own input, a layer past the repository where "never
 * touch a lazy-loaded property in application code" can no longer be
 * guaranteed, so carrying an entity reference out past the repository
 * boundary is the wrong shape here specifically.
 */
final readonly class PaginatedActivityRow
{
    /**
     * @param array<string, mixed>|null $details
     */
    public function __construct(
        public ?int $performedBy,
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
