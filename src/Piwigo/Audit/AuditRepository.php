<?php

declare(strict_types=1);

namespace Piwigo\Audit;

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
     * @return list<array{
     *   id: int,
     *   actorId: ?int,
     *   action: string,
     *   entityType: string,
     *   entityId: ?int,
     *   beforeJson: ?string,
     *   afterJson: ?string,
     *   ipAddress: ?string,
     *   createdAt: string,
     *   prevHash: ?string,
     *   rowHash: string,
     * }>
     */
    public function findAllInOrder(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'actor_id', 'action', 'entity_type', 'entity_id', 'before_json', 'after_json', 'ip_address', 'created_at', 'prev_hash', 'row_hash')
            ->from(Tables::auditLog())
            ->orderBy('id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'actorId' => is_numeric($row['actor_id']) ? (int) $row['actor_id'] : null,
                'action' => is_string($row['action']) ? $row['action'] : '',
                'entityType' => is_string($row['entity_type']) ? $row['entity_type'] : '',
                'entityId' => is_numeric($row['entity_id']) ? (int) $row['entity_id'] : null,
                'beforeJson' => is_string($row['before_json']) ? $row['before_json'] : null,
                'afterJson' => is_string($row['after_json']) ? $row['after_json'] : null,
                'ipAddress' => is_string($row['ip_address']) ? $row['ip_address'] : null,
                'createdAt' => is_string($row['created_at']) ? $row['created_at'] : '',
                'prevHash' => is_string($row['prev_hash']) ? $row['prev_hash'] : null,
                'rowHash' => is_string($row['row_hash']) ? $row['row_hash'] : '',
            ],
            $rows
        );
    }
}
