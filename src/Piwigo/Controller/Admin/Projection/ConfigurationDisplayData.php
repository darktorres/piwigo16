<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * The `'display'` tab's own display data, built by
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s
 * `'display'` case. Every field here is a fixed, statically-known key (23
 * of them come from `checkboxValue()`'s own literal `match` arms before
 * this conversion, confirmed as real bool `CurrentConfig` properties, not
 * a genuinely dynamic bag) -- `configuration_display.latte` reads them as
 * properties directly (P58-A). `$pictureInformations` stays array-shaped -- a confirmed dynamic
 * plugin-extensibility boundary (mirrors `Picture\Event\
 * FilterPictureDisplayInfo::$displayInfo` 1:1).
 */
final readonly class ConfigurationDisplayData
{
    /**
     * @param array<string, bool> $pictureInformations
     */
    public function __construct(
        public bool $menubarFilterIcon,
        public bool $indexSearchInSetButton,
        public bool $indexSearchInSetAction,
        public bool $indexSortOrderInput,
        public bool $indexFlatIcon,
        public bool $indexPostedDateIcon,
        public bool $indexCreatedDateIcon,
        public bool $indexSlideshowIcon,
        public bool $indexSizesIcon,
        public bool $indexNewIcon,
        public bool $indexEditIcon,
        public bool $indexCaddieIcon,
        public bool $displayFromto,
        public bool $pictureMetadataIcon,
        public bool $pictureSlideshowIcon,
        public bool $pictureFavoriteIcon,
        public bool $pictureSizesIcon,
        public bool $pictureDownloadIcon,
        public bool $pictureEditIcon,
        public bool $pictureCaddieIcon,
        public bool $pictureRepresentativeIcon,
        public bool $pictureNavigationIcons,
        public bool $pictureNavigationThumb,
        public bool $pictureMenu,
        public array $pictureInformations,
        public int $nbCategoriesPage,
    ) {}
}
