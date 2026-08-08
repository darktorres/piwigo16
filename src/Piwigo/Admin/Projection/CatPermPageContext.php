<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\CatPermPageRenderer::render()}. `$saveSuccess` and
 * `$nbUsersGrantedIndirect` are genuinely optional -- the original code
 * only ever assigns those 2 template keys under their own runtime
 * condition, omitted here (not present as a null value) to match that
 * exact original behavior.
 */
final readonly class CatPermPageContext implements TemplatePageContext
{
    /**
     * @param array<int, string> $groups
     * @param list<int> $groupsSelected
     * @param array<int|string, mixed> $users
     * @param list<int> $usersSelected
     * @param array<array-key, string> $cacheKeys
     */
    public function __construct(
        public ?string $saveSuccess,
        public string $categoriesNav,
        public string $helpUrl,
        public string $fAction,
        public bool $private,
        public array $groups,
        public array $groupsSelected,
        public array $users,
        public array $usersSelected,
        public ?int $nbUsersGrantedIndirect,
        public string $pwgToken,
        public bool $inherit,
        public array $cacheKeys,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'CATEGORIES_NAV' => $this->categoriesNav,
            'U_HELP' => $this->helpUrl,
            'F_ACTION' => $this->fAction,
            'private' => $this->private,
            'groups' => $this->groups,
            'groups_selected' => $this->groupsSelected,
            'users' => $this->users,
            'users_selected' => $this->usersSelected,
            'PWG_TOKEN' => $this->pwgToken,
            'INHERIT' => $this->inherit,
            'CACHE_KEYS' => $this->cacheKeys,
        ];

        if ($this->saveSuccess !== null) {
            $result['save_success'] = $this->saveSuccess;
        }

        if ($this->nbUsersGrantedIndirect !== null) {
            $result['nb_users_granted_indirect'] = $this->nbUsersGrantedIndirect;
        }

        return $result;
    }
}
