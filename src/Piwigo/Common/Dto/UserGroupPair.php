<?php

declare(strict_types=1);

namespace Piwigo\Common\Dto;

/**
 * (user_id, group_id) membership pair from the user_group table.
 */
final readonly class UserGroupPair
{
    public function __construct(
        public int $userId,
        public int $groupId,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            userId: is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : 0,
            groupId: is_numeric($row['group_id'] ?? null) ? (int) $row['group_id'] : 0,
        );
    }
}
