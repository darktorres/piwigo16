<?php

declare(strict_types=1);

namespace Piwigo\Activity;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\ActivityId;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Users\UserEntity;

/**
 * Maps the `activity` table. `occuredOn` is `SqlDateTime`-typed -- every real
 * write path traces to an `Env::now()`-derived value. `details` maps as native
 * Doctrine `json` -- no round-trip-fidelity requirement forces a raw-string
 * exception here, unlike Audit\AuditLogEntity's hash-chain columns.
 * `activityId` is `ActivityId`-typed -- its own primary key, not a reference
 * (see the SQL-modernization plan's `0.3` audit), so no other table's column
 * gains a matching foreign key. `UserActivityLogEntry`/`SystemActivityLogEntry`
 * both stay plain `int` (Projection convention), `fromRow()` narrowing via
 * `instanceof ActivityId`, not `is_numeric()`.
 *
 * `performedByUser` is a real `#[ORM\ManyToOne] ?UserEntity` association
 * (`fk_activity_performed_by`), not a scalar VO -- the schema's own
 * `ON DELETE SET NULL` is the only referential authority (no
 * `#[JoinColumn(onDelete: ...)]`, see `0.3`'s "No ORM cascades").
 * `nullable`/`referencedColumnName` are left unspecified deliberately, same
 * reasoning as `CategoryEntity::$representativePicture`. The 5 exclusive-arc
 * columns below (`userId`/`categoryId`/`imageId`/`tagId`/`groupId`) stay
 * scalar VOs -- explicitly out of `0.3`'s scope, a separate CHECK-constrained
 * polymorphic-adjacent decision.
 */
#[ORM\Entity(repositoryClass: ActivityRepository::class)]
#[ORM\Table(name: 'activity')]
final class ActivityEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'activity_id', type: 'activity_id')]
    public ?ActivityId $activityId = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 255)]
        public string $object,
        /**
         * The historical fact: which thing this event was about, at the
         * time it happened. Never nulled, so a record of a deletion still
         * says what was deleted -- see the typed columns below, and
         * Version20260804122302/Version20260804122303 for why both exist.
         *
         * For `object = 'system'` this is not a row id at all; that meaning
         * moved to $systemScope.
         */
        #[ORM\Column(name: 'object_id', type: 'integer')]
        public int $objectId,
        #[ORM\Column(type: 'string', length: 255)]
        public string $action,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'performed_by')]
        public ?UserEntity $performedByUser,
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
        /**
         * The live references. Exactly one is set on insert, chosen by
         * {@see ActivityObject::referenceColumn()}; each carries a real
         * foreign key with `ON DELETE SET NULL`, so none can ever dangle.
         * A null here with a non-null $objectId means the subject has since
         * been deleted, which for a log is the normal end state rather than
         * an error.
         */
        #[ORM\Column(name: 'user_id', type: 'user_id', nullable: true)]
        public ?UserId $userId = null,
        #[ORM\Column(name: 'category_id', type: 'category_id', nullable: true)]
        public ?CategoryId $categoryId = null,
        #[ORM\Column(name: 'image_id', type: 'image_id', nullable: true)]
        public ?ImageId $imageId = null,
        #[ORM\Column(name: 'tag_id', type: 'tag_id', nullable: true)]
        public ?TagId $tagId = null,
        #[ORM\Column(name: 'group_id', type: 'group_id', nullable: true)]
        public ?GroupId $groupId = null,
        /**
         * `ActivitySystem` constant for `object = 'system'` rows, which
         * previously overloaded $objectId.
         */
        #[ORM\Column(name: 'system_scope', type: 'integer', nullable: true)]
        public ?int $systemScope = null,
    ) {}
}
