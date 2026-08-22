<?php

declare(strict_types=1);

namespace Piwigo\Group\Projection;

use Piwigo\Common\ValueObject\GroupId;

/**
 * One row of `group_list.latte`'s `$groups` list, built by
 * {@see \Piwigo\Admin\GroupListPageRenderer::render()}. Only 4 fields --
 * `group_list.latte`'s own real body only reads `id`/`name`/`members`/
 * `isDefault` (confirmed: the group manager UI is entirely client-side,
 * driven by `group_list.js` against exposed page data instead), unlike
 * HANDOFF-array-to-object-campaign.md's own reference commit 0f21ac43f7,
 * whose 10-field row predates the dead-field pruning already landed on
 * this branch's own `GroupListView`. `$members` is already a translated,
 * pluralized display string, computed once at build time.
 */
final readonly class GroupListRow
{
    public function __construct(
        public GroupId $id,
        public string $name,
        public bool $isDefault,
        public string $members,
    ) {}

    /**
     * @return array{id: int, name: string, members: string, isDefault: bool}
     */
    public function toArray(): array
    {
        return [
            // Explicit ->value, not relying on GroupId's Stringable --
            // group_list.latte does real arithmetic on id
            // (`$group['id']%5`), which would TypeError against a bare
            // VO object.
            'id' => $this->id->value,
            'name' => $this->name,
            'members' => $this->members,
            'isDefault' => $this->isDefault,
        ];
    }
}
