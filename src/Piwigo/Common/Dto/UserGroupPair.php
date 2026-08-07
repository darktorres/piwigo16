<?php

declare(strict_types=1);

namespace Piwigo\Common\Dto;

use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\UserId;

/**
 * (user_id, group_id) membership pair from the user_group table.
 */
final readonly class UserGroupPair
{
    public function __construct(
        public UserId $userId,
        public GroupId $groupId,
    ) {}

    /**
     * $userId/$groupId are VOs, so a missing/malformed row throws via
     * UserId::from()/GroupId::from() instead of silently defaulting to a
     * fake id of 0 -- there's no legitimate "no membership" 0-sentinel
     * case for a (user_id, group_id) pair the way there might be for an
     * optional foreign key, matching the same "malformed-row fallback
     * throws" precedent as Image\Projection\ImageFormat::fromRow().
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $userId = $row['user_id'] ?? null;
        $groupId = $row['group_id'] ?? null;

        return new self(
            userId: UserId::from(is_numeric($userId) ? (int) $userId : 0),
            groupId: GroupId::from(is_numeric($groupId) ? (int) $groupId : 0),
        );
    }
}
