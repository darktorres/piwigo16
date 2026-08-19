<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `cat_perm.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\CatPermPageRenderer::render()}. No `$categoriesNav`/
 * `$users` fields -- the template's own body never references either
 * (`cat_perm.js` lazily loads the users/groups selectize options over
 * AJAX via `$cacheKeys`, not a pre-rendered username map).
 */
#[Template('cat_perm.latte')]
final readonly class CatPermView implements View
{
    /**
     * @param array<int, string> $groups
     * @param list<int> $groupsSelected
     * @param list<int> $usersSelected
     * @param list<array{group_name: string, group_users: string}>|null $userGrantedIndirectGroups
     * @param array<array-key, string> $cacheKeys
     */
    public function __construct(
        public string $fAction,
        public bool $private,
        public array $groups,
        public array $groupsSelected,
        public array $usersSelected,
        public ?int $nbUsersGrantedIndirect,
        public ?array $userGrantedIndirectGroups,
        public bool $inherit,
        public array $cacheKeys,
        public ?string $saveSuccess,
        public string $csrfToken,
    ) {}
}
