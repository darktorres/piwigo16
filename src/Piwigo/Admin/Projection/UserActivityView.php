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
 * `user_activity.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\UserActivityPageRenderer::render()}. No `$inherit` or
 * `$csrfToken` field -- the template's own body (and its own
 * `user_activity.js`) never reference either.
 */
#[Template('user_activity.latte')]
final readonly class UserActivityView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<array-key, string> $cacheKeys
     * @param list<array{id: int, username: string, nb_lines: int}> $ulist
     * @param array{min: string, max: string} $activityDates
     * @param list<array{object: string, action: string, counter: int, value: string}> $actions
     */
    public function __construct(
        public array $cacheKeys,
        public array $ulist,
        public int $nbUsers,
        public array $activityDates,
        public string|false $additionalFiltType,
        public ?string $additionalFiltName,
        public ?string $additionalFiltValue,
        public array $actions,
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
            AssetContribution::script('common', 'themes/admin/default/js/common.js', loadMode: LoadMode::Footer),
            AssetContribution::script('jquery.selectize', 'themes/default/js/plugins/selectize.min.js', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/default/js/plugins/selectize.' . $this->colorscheme . '.css', id: 'jquery.selectize'),
            AssetContribution::script('LocalStorageCache', 'themes/admin/default/js/LocalStorageCache.js', loadMode: LoadMode::Footer),
            // order 10 is required, see issue 1080
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            AssetContribution::css('themes/admin/default/css/pages/user_activity.css', id: 'user_activity'),
            AssetContribution::script('user_activity', 'themes/admin/default/js/user_activity.js', loadMode: LoadMode::Async, dependsOn: ['jquery', 'page-data', 'LocalStorageCache']),
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
            'nb_users' => $this->nbUsers,
            'additional_filt_type' => $this->additionalFiltType,
            'additional_filt_value' => $this->additionalFiltValue,
            'activity_dates_min' => $this->activityDates['min'],
            'activity_dates_max' => $this->activityDates['max'],
        ];
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'Users',
            '%s line',
            '%s lines',
            'add',
            'deletion',
            'move',
            'edit',
            'login',
            'logout',
            '%d album added',
            '%d album deleted',
            '%d album edited',
            '%d album moved',
            '%d albums added',
            '%d albums deleted',
            '%d albums edited',
            '%d albums moved',
            '%d user added',
            '%d user deleted',
            '%d user edited',
            '%d user logged in',
            '%d user logged out',
            '%d users added',
            '%d users deleted',
            '%d users edited',
            '%d users logged in',
            '%d users logged out',
            '%d photo added',
            '%d photo deleted',
            '%d photo edited',
            '%d photo moved',
            '%d photos added',
            '%d photos deleted',
            '%d photos edited',
            '%d photos moved',
            '%d group added',
            '%d group deleted',
            '%d group edited',
            '%d group moved',
            '%d groups added',
            '%d groups deleted',
            '%d groups edited',
            '%d groups moved',
            '%d tag added',
            '%d tag deleted',
            '%d tag edited',
            '%d tag moved',
            '%d tags added',
            '%d tags deleted',
            '%d tags edited',
            '%d tags moved',
        ];
    }
}
