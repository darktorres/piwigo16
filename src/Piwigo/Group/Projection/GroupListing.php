<?php

declare(strict_types=1);

namespace Piwigo\Group\Projection;

use Piwigo\Common\ValueObject\GroupId;

/**
 * Typed row shape for {@see \Piwigo\Group\GroupRepository::findWithMemberCounts()}'s
 * own `g.*` + `COUNT(...) AS nb_users` raw-DBAL row -- distinct from the
 * sibling {@see Group} Projection, which only covers `findAllBasic()`'s
 * narrower `id`/`name`/`is_default` selection.
 *
 * `toArray()` preserves the exact snake_case shape
 * {@see \Piwigo\Ws\PwgGroups::getList()}'s own JSON/XML response already
 * commits to (`tests/Contract/schemas/pwg.groups.getList.json` requires
 * `is_default`/`nb_users` literally) -- the WS call site converts back to
 * an array via `toArray()` before handing rows to `PwgNamedArray`, same
 * boundary-unwrap convention every other Projection in this codebase uses.
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

    /**
     * @return array{id: int, name: string, is_default: bool, lastmodified: string, nb_users: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->value,
            'name' => $this->name,
            'is_default' => $this->isDefault,
            'lastmodified' => $this->lastmodified,
            'nb_users' => $this->nbUsers,
        ];
    }
}
