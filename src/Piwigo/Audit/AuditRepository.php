<?php

declare(strict_types=1);

namespace Piwigo\Audit;

use Piwigo\Audit\Projection\AuditLogEntry;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the audit domain: `audit_log` [SEC-57]. Only ever
 * appends -- no update/delete method exists here, by design (tamper
 * evidence relies on rows never changing after being written).
 */
final class AuditRepository extends AbstractRepository
{
    public function insert(
        ?int $actorId,
        string $action,
        string $entityType,
        ?int $entityId,
        ?string $beforeJson,
        ?string $afterJson,
        ?string $ipAddress,
        string $createdAt,
        ?string $prevHash,
        string $rowHash,
    ): int {
        $this->conn->createQueryBuilder()
            ->insert(Tables::auditLog())
            ->values([
                'actor_id' => ':actorId',
                'action' => ':action',
                'entity_type' => ':entityType',
                'entity_id' => ':entityId',
                'before_json' => ':beforeJson',
                'after_json' => ':afterJson',
                'ip_address' => ':ipAddress',
                'created_at' => ':createdAt',
                'prev_hash' => ':prevHash',
                'row_hash' => ':rowHash',
            ])
            ->setParameter('actorId', $actorId)
            ->setParameter('action', $action)
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->setParameter('beforeJson', $beforeJson)
            ->setParameter('afterJson', $afterJson)
            ->setParameter('ipAddress', $ipAddress)
            ->setParameter('createdAt', $createdAt)
            ->setParameter('prevHash', $prevHash)
            ->setParameter('rowHash', $rowHash)
            ->executeStatement();

        return (int) $this->conn->lastInsertId();
    }

    /**
     * The most recently written row's own hash -- the chain's current
     * tip, needed to link the next row in. Null when the log is empty
     * (the very first row ever written has no prev_hash).
     */
    public function findLatestRowHash(): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('row_hash')
            ->from(Tables::auditLog())
            ->orderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : null;
    }

    /**
     * Every row in insertion order, for chain verification and for the
     * (future, P29) admin viewer.
     *
     * @return list<AuditLogEntry>
     */
    public function findAllInOrder(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'actor_id', 'action', 'entity_type', 'entity_id', 'before_json', 'after_json', 'ip_address', 'created_at', 'prev_hash', 'row_hash')
            ->from(Tables::auditLog())
            ->orderBy('id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(AuditLogEntry::fromRow(...), $rows);
    }
}
