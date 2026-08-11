<?php

declare(strict_types=1);

namespace Piwigo\Group\Projection;

use InvalidArgumentException;
use Piwigo\Common\ValueObject\GroupId;

/**
 * Typed row shape for `groups`. `fromRow()` centralises the
 * `is_string($row['x']) ? ... :
 * default` narrowing {@see \Piwigo\Group\GroupRepository::findAllBasic()}
 * used to do inline, same shape as {@see \Piwigo\Tag\Projection\Tag}.
 *
 * Scoped to `findAllBasic()`'s own 3-column projection (`id`/`name`/
 * `is_default`), not every `groups` column -- {@see
 * \Piwigo\Group\GroupRepository::findWithMemberCounts()}'s own `g.*` +
 * `COUNT(...) AS nb_users` query stays a raw array, same deliberate
 * deferral as {@see \Piwigo\Tag\TagRepository::findCommonTags()}: it feeds
 * `Ws\Groups::getList()`'s own JSON response directly, with exactly one
 * real caller and no per-field access to centralise.
 *
 * `id` is `GroupId`, not `int` -- the first Projection DTO in this
 * codebase to get real id typing (the Common\ValueObject VO layer). This
 * makes `fromRow()`'s previous "null/malformed id defaults to 0" contract
 * (still the convention on every other Projection, e.g. `Tag::fromRow()`)
 * structurally impossible to keep: `GroupId::from(0)` always throws, 0 was
 * never a valid id. `fromRow()` now throws on a null/malformed id instead
 * of silently defaulting -- deliberate, not an oversight: `fromRow()` has
 * no real caller anywhere in `src/Piwigo` (only `findAllBasic()`'s own
 * direct `new Group(...)` construction is real), so there's no production
 * behavior being changed, and a silently-wrong `Group` is exactly the
 * failure mode typing this field exists to convert into a loud one.
 */
final readonly class Group
{
    public function __construct(
        public GroupId $id,
        public string $name,
        public bool $isDefault,
    ) {}

    /**
     * @param array<string, mixed> $row a {@see \Piwigo\Group\GroupRepository::findAllBasic()} row
     * @throws InvalidArgumentException when $row['id'] is missing or not a valid positive id
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: GroupId::from(is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0),
            name: is_string($row['name'] ?? null) ? $row['name'] : '',
            isDefault: (bool) ($row['is_default'] ?? false),
        );
    }

    /**
     * @return array{id: int, name: string, is_default: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->value,
            'name' => $this->name,
            'is_default' => $this->isDefault,
        ];
    }
}
