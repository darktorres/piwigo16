<?php

declare(strict_types=1);

namespace Piwigo\Activity;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;

/**
 * Maps the `activity` table (`piwigo_activity` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time).
 * `occuredOn` is `SqlDateTime`-typed -- every real write path
 * traces to an `Env::now()`-derived value. `details` maps as native
 * Doctrine `json` -- no round-trip-fidelity requirement forces a
 * raw-string exception here, unlike Audit\AuditLogEntity's hash-chain
 * columns.
 */
#[ORM\Entity(repositoryClass: ActivityRepository::class)]
#[ORM\Table(name: 'activity')]
final class ActivityEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'activity_id', type: 'integer')]
    public ?int $activityId = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 255)]
        public string $object,
        #[ORM\Column(name: 'object_id', type: 'integer')]
        public int $objectId,
        #[ORM\Column(type: 'string', length: 255)]
        public string $action,
        #[ORM\Column(name: 'performed_by', type: 'user_id', nullable: true)]
        public ?UserId $performedBy,
        #[ORM\Column(name: 'session_idx', type: 'string', length: 255)]
        public string $sessionIdx,
        #[ORM\Column(name: 'ip_address', type: 'ip_address', length: 50, nullable: true)]
        public ?IpAddress $ipAddress,
        #[ORM\Column(name: 'occured_on', type: 'sql_datetime', length: 19)]
        public SqlDateTime $occuredOn,
        /**
         * @var array<string, mixed>|null
         */
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $details,
        #[ORM\Column(name: 'user_agent', type: 'string', length: 255, nullable: true)]
        public ?string $userAgent,
    ) {}
}
