<?php

declare(strict_types=1);

namespace Piwigo\Group\Projection;

use Piwigo\Common\ValueObject\GroupId;

/**
 * Typed row shape for {@see \Piwigo\Group\GroupRepository::findWithMemberCounts()}'s
 * own `g.*` + `COUNT(...) AS nb_users` raw-DBAL row -- distinct from the
 * sibling {@see Group} Projection, which only covers `findAllBasic()`'s
 * narrower `id`/`name`/`is_default` selection.
 */
final readonly class GroupListing
{
    public function __construct(
        public GroupId $id,
        public string $name,
        public bool $isDefault,
        public string $lastmodified,
        public int $nbUsers,
    ) {}

    /**
     * @param array<string, mixed> $row a {@see \Piwigo\Group\GroupRepository::findWithMemberCounts()} row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: GroupId::from(is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0),
            name: is_string($row['name'] ?? null) ? $row['name'] : '',
            isDefault: (bool) ($row['is_default'] ?? false),
            lastmodified: is_string($row['lastmodified'] ?? null) ? $row['lastmodified'] : '',
            nbUsers: is_numeric($row['nb_users'] ?? null) ? (int) $row['nb_users'] : 0,
        );
    }
}
