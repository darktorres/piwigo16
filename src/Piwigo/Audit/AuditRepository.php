<?php

declare(strict_types=1);

namespace Piwigo\Audit;

use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\UserId;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Piwigo\Audit\Projection\AuditLogEntry;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\SqlDateTime;

/**
 * Persistence layer for the audit domain: `audit_log` [SEC-57]. Only ever
 * appends -- no update/delete method exists here, by design (tamper
 * evidence relies on rows never changing after being written).
 *
 * @extends EntityRepository<AuditLogEntity>
 */
final class AuditRepository extends EntityRepository
{
    public function insert(
        ?UserId $actorId,
        string $action,
        string $entityType,
        ?int $entityId,
        ?string $beforeJson,
        ?string $afterJson,
        ?IpAddress $ipAddress,
        SqlDateTime $createdAt,
        ?string $prevHash,
        string $rowHash,
    ): int {
        $entity = new AuditLogEntity(
            actorId: $actorId,
            action: $action,
            entityType: $entityType,
            entityId: $entityId,
            beforeJson: $beforeJson,
            afterJson: $afterJson,
            ipAddress: $ipAddress,
            createdAt: $createdAt,
            prevHash: $prevHash,
            rowHash: $rowHash,
            groupId: $this->liveGroupId($entityType, $entityId),
        );

        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();

        // Populated by flush() above -- GeneratedValue auto-increment PK.
        assert($entity->id !== null);

        return $entity->id;
    }

    /**
     * The most recently written row's own hash -- the chain's current
     * tip, needed to link the next row in. Null when the log is empty
     * (the very first row ever written has no prev_hash).
     */
    /**
     * The typed reference, but only when the group still exists.
     *
     * The only current writer records a group *deletion*, so by the time
     * this runs the row is usually gone and the foreign key would reject the
     * insert -- failing the very operation being audited. entity_type and
     * entity_id keep the historical record regardless, and they are what
     * AuditService::computeHash() folds into the chain, so nothing about the
     * evidence depends on this column.
     */
    private function liveGroupId(string $entityType, ?int $entityId): ?GroupId
    {
        if ($entityType !== 'group' || $entityId === null) {
            return null;
        }

        $found = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('1')
            ->from($this->getEntityManager()->getConnection()->getDatabasePlatform()->quoteSingleIdentifier('groups'))
            ->where('id = :id')
            ->setParameter('id', $entityId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $found === false ? null : GroupId::tryFrom($entityId);
    }

    public function findLatestRowHash(): ?string
    {
        $entities = $this->createQueryBuilder('a')
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            // HINT_REFRESH -- this table is only ever appended to (never
            // updated in normal operation), but chain verification
            // (findAllInOrder() below) specifically exists to detect
            // tampering that bypasses this repository entirely (a raw
            // UPDATE). Without this hint, a row this same EntityManager
            // already loaded (e.g. the one insert() above just persisted)
            // would hydrate from Doctrine's identity map instead of the
            // real current row -- silently hiding exactly the kind of
            // out-of-band mutation this method exists to catch.
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $entities[0]->rowHash ?? null;
    }

    /**
     * Every row in insertion order, for chain verification and for a
     * future admin viewer.
     *
     * @return list<AuditLogEntry>
     */
    public function findAllInOrder(): array
    {
        $entities = $this->createQueryBuilder('a')
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            // HINT_REFRESH -- see findLatestRowHash()'s own comment above.
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return array_map(
            static function (AuditLogEntity $entity): AuditLogEntry {
                assert($entity->id !== null);

                return new AuditLogEntry(
                    id: $entity->id,
                    actorId: $entity->actorId,
                    action: $entity->action,
                    entityType: $entity->entityType,
                    entityId: $entity->entityId,
                    beforeJson: $entity->beforeJson,
                    afterJson: $entity->afterJson,
                    ipAddress: $entity->ipAddress,
                    createdAt: $entity->createdAt->value,
                    prevHash: $entity->prevHash,
                    rowHash: $entity->rowHash,
                );
            },
            $entities,
        );
    }
}
