<?php

declare(strict_types=1);

namespace Piwigo\Group\Projection;

/** (id, name, nb_users_of) row from GroupRepository::findWithMemberCounts(). */
final readonly class GroupWithCount
{
    public function __construct(
        public int $id,
        public string $name,
        public int $nbUsersOf,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:       is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            name:     is_string($row['name'] ?? null) ? $row['name'] : '',
            nbUsersOf: is_numeric($row['nb_users_of'] ?? null) ? (int) $row['nb_users_of'] : 0,
        );
    }
}
