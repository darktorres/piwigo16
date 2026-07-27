<?php

declare(strict_types=1);

namespace Piwigo\Group\Projection;

/**
 * Typed row shape for `piwigo_groups` (P17-23 Stage 1b, Group domain --
 * `docs/PLAN.md`'s own "7 Entity types, 73 projection shapes"
 * reference). `fromRow()` centralises the `is_string($row['x']) ? ... :
 * default` narrowing {@see \Piwigo\Group\GroupRepository::findAllBasic()}
 * used to do inline, same shape as {@see \Piwigo\Tag\Projection\Tag}.
 *
 * Scoped to `findAllBasic()`'s own 3-column projection (`id`/`name`/
 * `is_default`), not every `piwigo_groups` column -- {@see
 * \Piwigo\Group\GroupRepository::findWithMemberCounts()}'s own `g.*` +
 * `COUNT(...) AS nb_users` query stays a raw array, same deliberate
 * deferral as {@see \Piwigo\Tag\TagRepository::findCommonTags()}: it feeds
 * `Ws\PwgGroups::getList()`'s own JSON response directly, with exactly one
 * real caller and no per-field access to centralise.
 */
final readonly class Group
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $isDefault,
    ) {}

    /**
     * @param array<string, mixed> $row a {@see \Piwigo\Group\GroupRepository::findAllBasic()} row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
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
            'id' => $this->id,
            'name' => $this->name,
            'is_default' => $this->isDefault,
        ];
    }
}
