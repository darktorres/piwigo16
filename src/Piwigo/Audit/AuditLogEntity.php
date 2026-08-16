<?php

declare(strict_types=1);

namespace Piwigo\Audit;

use Piwigo\Common\ValueObject\UserId;
use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\SqlDateTime;

/**
 * Maps the `audit_log` table. `before_json`/`after_json` map as plain `text`,
 * not Doctrine's `json` type -- AuditService::record()/verifyChain() already
 * do their own json_encode()/json_decode() (the hash chain needs the exact raw
 * bytes MySQL's JSON column gives back, see AuditService::canonicalJson()'s
 * own docblock); Doctrine's `json` type would decode on read and re-encode a
 * PHP value on write, corrupting an already-encoded string handed to it.
 * `created_at` is `SqlDateTime`-typed -- the one real write path traces to an
 * `Env::now()`-derived value. This is orthogonal to the hash chain:
 * `AuditService::computeHash()` hashes the plain `Y-m-d H:i:s` string directly
 * (never re-reads it off this entity), and `SqlDateTime`'s own
 * `__toString()`/`->value` round-trip byte-identically to that same string, so
 * the chain's content is unaffected either way. Insert-only (only
 * `Audit\Projection\AuditLogEntry`, a separate readonly DTO, is used for
 * reads) -- no update method needed on the entity.
 */
#[ORM\Entity(repositoryClass: AuditRepository::class)]
#[ORM\Table(name: 'audit_log')]
final class AuditLogEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(name: 'actor_id', type: 'user_id', nullable: true)]
        public ?UserId $actorId,
        #[ORM\Column(type: 'string', length: 64)]
        public string $action,
        #[ORM\Column(name: 'entity_type', type: 'string', length: 64)]
        public string $entityType,
        #[ORM\Column(name: 'entity_id', type: 'integer', nullable: true)]
        public ?int $entityId,
        #[ORM\Column(name: 'before_json', type: 'text', nullable: true)]
        public ?string $beforeJson,
        #[ORM\Column(name: 'after_json', type: 'text', nullable: true)]
        public ?string $afterJson,
        #[ORM\Column(name: 'ip_address', type: 'ip_address', length: 45, nullable: true)]
        public ?IpAddress $ipAddress,
        #[ORM\Column(name: 'created_at', type: 'sql_datetime', length: 19)]
        public SqlDateTime $createdAt,
        #[ORM\Column(name: 'prev_hash', type: 'string', length: 64, nullable: true)]
        public ?string $prevHash,
        #[ORM\Column(name: 'row_hash', type: 'string', length: 64)]
        public string $rowHash,
        /**
         * Live reference for `entity_type = 'group'`, with a foreign key
         * that nulls on deletion. `entity_type`/`entity_id` stay the
         * historical record: AuditService::computeHash() folds `entity_id`
         * into every row_hash, so it cannot move without invalidating the
         * chain. More typed columns join this as more entity types appear.
         */
        #[ORM\Column(name: 'group_id', type: 'group_id', nullable: true)]
        public ?GroupId $groupId = null,
    ) {}
}
