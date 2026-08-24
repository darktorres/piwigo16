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
    ) {}

    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            ...new DatepickerView(jqueryCode: $this->jqueryCode)
                ->pageAssets(),
            AssetContribution::script('common', 'themes/admin/default/js/common.ts', loadMode: LoadMode::Footer),
            AssetContribution::script('history', 'themes/admin/default/js/history.ts', loadMode: LoadMode::Footer, dependsOn: ['page-data']),
            AssetContribution::script('jquery.confirm', 'https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            // order 10 is required, see issue 1080
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            AssetContribution::css('themes/default/vendor/fontello/css/gallery-icon.css', order: -10),
            AssetContribution::script('jquery.geoip', 'themes/admin/default/js/jquery.geoip.js', loadMode: LoadMode::Async),
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
