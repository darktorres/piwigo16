<?php

declare(strict_types=1);

namespace Piwigo\Audit\Projection;

use Piwigo\Common\ValueObject\IpAddress;

/**
 * Typed row shape for `piwigo_audit_log` (P17-23 Stage 1b, Audit domain --
 * `docs/PLAN.md`'s own "7 Entity types, 73 projection shapes"
 * reference). `fromRow()` centralises the `is_numeric($row['x']) ? ... :
 * default` narrowing a raw DBAL row read of this table would need -- unlike
 * {@see \Piwigo\Audit\AuditRepository::findAllInOrder()}'s own inline
 * `array_map()` closure, which builds this shape straight off a typed
 * {@see AuditLogEntity}'s properties instead, same shape as
 * {@see \Piwigo\Category\Projection\Category}.
 */
final readonly class AuditLogEntry
{
    public function __construct(
        public int $id,
        public ?int $actorId,
        public string $action,
        public string $entityType,
        public ?int $entityId,
        public ?string $beforeJson,
        public ?string $afterJson,
        public ?IpAddress $ipAddress,
        public string $createdAt,
        public ?string $prevHash,
        public string $rowHash,
    ) {}

    /**
     * @param array<string, mixed> $row a `SELECT id, actor_id, action, entity_type, entity_id, before_json, after_json, ip_address, created_at, prev_hash, row_hash` row from `piwigo_audit_log`
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            actorId: is_numeric($row['actor_id'] ?? null) ? (int) $row['actor_id'] : null,
            action: is_string($row['action'] ?? null) ? $row['action'] : '',
            entityType: is_string($row['entity_type'] ?? null) ? $row['entity_type'] : '',
            entityId: is_numeric($row['entity_id'] ?? null) ? (int) $row['entity_id'] : null,
            beforeJson: is_string($row['before_json'] ?? null) ? $row['before_json'] : null,
            afterJson: is_string($row['after_json'] ?? null) ? $row['after_json'] : null,
            ipAddress: is_string($row['ip_address'] ?? null) ? IpAddress::tryFrom($row['ip_address']) : null,
            createdAt: is_string($row['created_at'] ?? null) ? $row['created_at'] : '',
            prevHash: is_string($row['prev_hash'] ?? null) ? $row['prev_hash'] : null,
            rowHash: is_string($row['row_hash'] ?? null) ? $row['row_hash'] : '',
        );
    }

    /**
     * @return array{id: int, actor_id: ?int, action: string, entity_type: string,
     *   entity_id: ?int, before_json: ?string, after_json: ?string, ip_address: ?string,
     *   created_at: string, prev_hash: ?string, row_hash: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'actor_id' => $this->actorId,
            'action' => $this->action,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'before_json' => $this->beforeJson,
            'after_json' => $this->afterJson,
            'ip_address' => $this->ipAddress?->value,
            'created_at' => $this->createdAt,
            'prev_hash' => $this->prevHash,
            'row_hash' => $this->rowHash,
        ];
    }
}
