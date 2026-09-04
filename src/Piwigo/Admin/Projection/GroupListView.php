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
 * `group_list.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\GroupListPageRenderer::render()}. `$groups` is always
 * included (even empty) since `group_list.latte` reads it with
 * `{if !empty($groups)}`, not `isset()`. No `$addAction` field, and
 * each `$groups` row carries only `id`/`name`/`members`/`isDefault`
 * -- confirmed dead in `group_list.latte`'s own real body:
 * `F_ADD_ACTION` and the per-row `L_MEMBERS`/`NB_MEMBERS`/`U_DELETE`/
 * `U_PERM`/`U_USERS`/`U_ISDEFAULT` keys have zero real references
 * (the group manager UI is entirely client-side, driven by
 * `group_list.js` against the exposed `cache_key_users`/`root_url`/
 * `csrf_token` data instead).
 */
#[Template('group_list.latte')]
final readonly class GroupListView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<array-key, string> $cacheKeys
     * @param list<array{id: int, name: string, members: string, isDefault: bool}> $groups
     */
    public function __construct(
        public string $pwgToken,
        public array $cacheKeys,
        public array $groups,
        public string $rootUrl,
        public string $colorscheme,
    ) {}

    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            ...new ColorboxView()
                ->pageAssets(),
            AssetContribution::css('themes/default/js/plugins/selectize.' . $this->colorscheme . '.css'),
            AssetContribution::script('group_list', 'themes/admin/default/js/users/groupList.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.css'),
            // order 10 is required, see issue 1080
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
        ];
    }

    /**
     * @return array<string, string|int|float|bool|null|array<mixed>>
     */
    #[Override]
    public function exposedPageData(): array
    {
        return [
            'cache_key_users' => $this->cacheKeys['users'],
            'cache_key_hash' => $this->cacheKeys['_hash'],
            'root_url' => $this->rootUrl,
            'csrf_token' => $this->pwgToken,
        ];
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'member',
            'members',
            'Group added',
            'Group renamed',
            'Name is already taken',
            'Name field must not be empty',
            'Group "%s" succesfully deleted',
            'Groups {%s} succesfully deleted',
            'Set as group for new users',
            'Unset as group for new users',
            'Are you sure you want to delete group "%s"?',
            'Yes, delete',
            'User associated',
            'Dissociate user from this group',
            'User "%s" dissociated from this group',
            'Manage the members',
            'Group(s) {%s1} succesfully merged into "%s2"',
            ' (copy)',
            ' (copy %s)',
        ];
    }
}
