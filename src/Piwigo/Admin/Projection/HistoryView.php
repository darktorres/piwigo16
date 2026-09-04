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
 * `history.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\HistoryPageRenderer::render()}. No `$fAction` field --
 * `F_ACTION` has zero real references in `history.latte`'s own body
 * (its `<form>` submits nowhere; the real search runs client-side
 * against `GET /api/v1/history/search`).
 */
#[Template('history.latte')]
final readonly class HistoryView implements View, HasPageAssets, ExposesPageData
{
    public function __construct(
        public int $userId,
        public ?string $userName,
        public string $imageId,
        public string $ip,
        public string $start,
        public string $end,
        public int $guestId,
        public string $jqueryCode,
        public bool $geoIpAvailable,
    ) {}

    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            // No more separate `history_page` bundle entry (docs/PLAN.md
            // P48/P49-B): it existed only to trigger Rollup's shared
            // chunking of `datepicker.ts`, itself a side-effect-only
            // import with no other real code of its own. Now that
            // `vendor/widgets/datepicker.ts` (P49-B, native port) is imported
            // directly by `history.ts` below, that indirection has no
            // remaining purpose. `jquery-ui.css`/
            // `jquery-ui-timepicker-addon.min.css` still theme the
            // native port's own reused class names.
            AssetContribution::script('history', 'themes/admin/default/js/history.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.10.4/css/jquery-ui.css', id: 'jquery.ui'),
            AssetContribution::css('https://cdn.jsdelivr.net/gh/trentrichardson/jQuery-Timepicker-Addon@v1.4.4/dist/jquery-ui-timepicker-addon.min.css'),
            // order 10 is required, see issue 1080
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            AssetContribution::css('themes/default/vendor/fontello/css/gallery-icon.css', order: -10),
            AssetContribution::css('themes/admin/default/css/pages/history.css', id: 'history'),
        ];
    }

    /**
     * @return array<string, string|int|float|bool|null|array<mixed>>
     */
    #[Override]
    public function exposedPageData(): array
    {
        return [
            'user_name' => $this->userName ?? '',
            'user_id' => $this->userId,
            'image_id' => $this->imageId,
            'ip' => $this->ip,
            'guest_id' => $this->guestId,
            'jquery_code' => $this->jqueryCode,
        ];
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'Downloaded',
            'Most visited',
            'Best rated',
            'Random photo',
            'Your favorites',
            'Recent albums',
            'Recent photos',
            'Memories',
            'This photo no longer exists',
            'Tags',
            '%s MB',
            'guest',
            'Contact Form',
            'Edit photo',
            'Search for words',
            'Post date',
            'Album',
            'Author',
            'Added by',
            'File type',
            'and %d more',
        ];
    }
}
