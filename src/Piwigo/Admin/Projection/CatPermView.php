<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `cat_perm.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\CatPermPageRenderer::render()}. No `$categoriesNav`/
 * `$users` fields -- the template's own body never references either
 * (`cat_perm.js` lazily loads the users/groups selectize options over
 * AJAX via `$cacheKeys`, not a pre-rendered username map). `$colorscheme`
 * and `$rootUrl` are the ambient `$themeconf['colorscheme']`/`$ROOT_URL`
 * the template's own `combineCss`/`exposeData` calls read -- the
 * controller resolves both the same way `Template` itself would, via
 * `$template->themeConf('colorscheme')`/`$urlService->getRootUrl()`.
 */
#[Template('cat_perm.latte')]
final readonly class CatPermView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<int, string> $groups
     * @param list<int> $groupsSelected
     * @param list<int> $usersSelected
     * @param list<array{group_name: string, group_users: string}> $userGrantedIndirectGroups
     * @param array<array-key, string> $cacheKeys
     */
    public function __construct(
        public string $fAction,
        public bool $private,
        public array $groups,
        public array $groupsSelected,
        public array $usersSelected,
        public ?int $nbUsersGrantedIndirect,
        public array $userGrantedIndirectGroups,
        public bool $inherit,
        public array $cacheKeys,
        public ?string $saveSuccess,
        public string $csrfToken,
        public string $colorscheme,
        public string $rootUrl,
    ) {}

    /**
     * `cat_perm.latte`'s own unconditional `{do combineScript(...)}`x4/
     * `{do combineCss(...)}`x2 (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('themes/default/js/plugins/selectize.' . $this->colorscheme . '.css', id: 'jquery.selectize'),
            AssetContribution::script('cat_perm', 'themes/admin/default/js/cat_perm.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/admin/default/css/pages/cat_perm.css', id: 'cat_perm'),
        ];
    }

    #[Override]
    public function exposedPageData(): array
    {
        return [
            'cache_key_groups' => $this->cacheKeys['groups'] ?? '',
            'cache_key_users' => $this->cacheKeys['users'] ?? '',
            'cache_key_hash' => $this->cacheKeys['_hash'] ?? '',
            'root_url' => $this->rootUrl,
        ];
    }

    #[Override]
    public function exposedStrings(): array
    {
        return [];
    }
}
