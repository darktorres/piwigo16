<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;
use Piwigo\Group\Projection\GroupListRow;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\GroupListPageRenderer::render()}. `$groups` is
 * always included (even empty) since `group_list.latte` reads it with
 * `{if !empty($groups)}`, not `isset()`.
 */
final readonly class GroupListPageContext implements TemplatePageContext
{
    /**
     * @param array<array-key, string> $cacheKeys
     * @param list<GroupListRow> $groups
     */
    public function __construct(
        public string $addAction,
        public string $pwgToken,
        public array $cacheKeys,
        public string $adminPageTitle,
        public array $groups,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'F_ADD_ACTION' => $this->addAction,
            'CSRF_TOKEN' => $this->pwgToken,
            'CACHE_KEYS' => $this->cacheKeys,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'groups' => array_map(static fn (GroupListRow $group): array => $group->toArray(), $this->groups),
        ];
    }
}
