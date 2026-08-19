<?php

declare(strict_types=1);

namespace Piwigo\Group\Projection;

use Piwigo\Common\ValueObject\GroupId;

/**
 * One row of `group_list.latte`'s `$groups` list, built by
 * {@see \Piwigo\Admin\GroupListPageRenderer::render()}. `$isDefaultLabel`/
 * `$membersLabel` are already display-formatted strings (translated,
 * pluralized) -- computed once at build time, same as this codebase's
 * other renderer-row Projections, not raw data the template re-derives.
 */
final readonly class GroupListRow
{
    public function __construct(
        public string $name,
        public GroupId $id,
        public string $isDefaultLabel,
        public int $nbMembers,
        public string $membersList,
        public string $membersLabel,
        public string $deleteUrl,
        public string $permUrl,
        public string $usersUrl,
        public string $toggleDefaultUrl,
    ) {}

    /**
     * @return array{NAME: string, ID: int, IS_DEFAULT: string, NB_MEMBERS: int,
     *     L_MEMBERS: string, MEMBERS: string, U_DELETE: string, U_PERM: string,
     *     U_USERS: string, U_ISDEFAULT: string}
     */
    public function toArray(): array
    {
        return [
            'NAME' => $this->name,
            // Explicit ->value, not relying on GroupId's Stringable --
            // group_list.latte does real arithmetic on ID (`$group['ID']%5`),
            // which would TypeError against a bare VO object.
            'ID' => $this->id->value,
            'IS_DEFAULT' => $this->isDefaultLabel,
            'NB_MEMBERS' => $this->nbMembers,
            'L_MEMBERS' => $this->membersList,
            'MEMBERS' => $this->membersLabel,
            'U_DELETE' => $this->deleteUrl,
            'U_PERM' => $this->permUrl,
            'U_USERS' => $this->usersUrl,
            'U_ISDEFAULT' => $this->toggleDefaultUrl,
        ];
    }
}
