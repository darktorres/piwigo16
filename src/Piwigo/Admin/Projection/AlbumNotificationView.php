<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `album_notification.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\AlbumNotificationPageRenderer::render()}. No
 * `$categoriesNav` field -- the template's own body never references
 * it. Every remaining optional field stays optional -- the template's
 * own body reads all of them through `isset()` guards, never a bare
 * truthy check, so an always-present `null` behaves identically to the
 * original conditionally-omitted key. `$saveSuccess` is also mutually
 * exclusive across its 2 original call sites (notify by users vs. by
 * group). `$colorscheme` is the ambient `$themeconf['colorscheme']`
 * the template's own `combineCss(id: 'jquery.selectize', ...)` call
 * reads -- the controller resolves it the same way `Template` itself
 * would, via `$template->themeConf('colorscheme')`.
 */
#[Template('album_notification.latte')]
final readonly class AlbumNotificationView implements View, HasPageAssets
{
    /**
     * @param array<int, string>|null $groupMailOptions
     * @param array<int, string>|null $userOptions
     */
    public function __construct(
        public ?string $saveSuccess,
        public string $fAction,
        public string $csrfToken,
        public ?string $authKeyDuration,
        public ?bool $noGroupInGallery,
        public ?string $permissionUrl,
        public ?array $groupMailOptions,
        public ?array $userOptions,
        public string $colorscheme,
    ) {}

    /**
     * `album_notification.latte`'s own unconditional `{do combineScript(...)}`x3/
     * `{do combineCss(...)}`x2 (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('themes/default/js/plugins/selectize.' . $this->colorscheme . '.css', id: 'jquery.selectize'),
            AssetContribution::script('album_notification', 'themes/admin/default/js/albumNotification.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/admin/default/css/pages/album_notification.css', id: 'album_notification'),
        ];
    }
}
