<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * The `'display'` tab's own display data, built by
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s
 * `'display'` case. Every field here is a fixed, statically-known key (23
 * of them come from `checkboxValue()`'s own literal `match` arms before
 * this conversion, confirmed as real bool `CurrentConfig` properties, not
 * a genuinely dynamic bag) -- `configuration_display.latte` still reads
 * them via `$display['key']` (through {@see ConfigurationDisplayView}'s
 * own array-typed `$display`), so `toArray()` reproduces that exact
 * shape. `$indexSearchInSetAction` is the one checkbox-list entry that
 * isn't actually boolean (a `'results'|'filter'` string,
 * {@see \Piwigo\Config\CurrentConfig::$indexSearchInSetAction}).
 * `$pictureInformations` stays array-shaped -- a confirmed dynamic
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
        public string $indexSearchInSetAction,
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'menubar_filter_icon' => $this->menubarFilterIcon,
            'index_search_in_set_button' => $this->indexSearchInSetButton,
            'index_search_in_set_action' => $this->indexSearchInSetAction,
            'index_sort_order_input' => $this->indexSortOrderInput,
            'index_flat_icon' => $this->indexFlatIcon,
            'index_posted_date_icon' => $this->indexPostedDateIcon,
            'index_created_date_icon' => $this->indexCreatedDateIcon,
            'index_slideshow_icon' => $this->indexSlideshowIcon,
            'index_sizes_icon' => $this->indexSizesIcon,
            'index_new_icon' => $this->indexNewIcon,
            'index_edit_icon' => $this->indexEditIcon,
            'index_caddie_icon' => $this->indexCaddieIcon,
            'display_fromto' => $this->displayFromto,
            'picture_metadata_icon' => $this->pictureMetadataIcon,
            'picture_slideshow_icon' => $this->pictureSlideshowIcon,
            'picture_favorite_icon' => $this->pictureFavoriteIcon,
            'picture_sizes_icon' => $this->pictureSizesIcon,
            'picture_download_icon' => $this->pictureDownloadIcon,
            'picture_edit_icon' => $this->pictureEditIcon,
            'picture_caddie_icon' => $this->pictureCaddieIcon,
            'picture_representative_icon' => $this->pictureRepresentativeIcon,
            'picture_navigation_icons' => $this->pictureNavigationIcons,
            'picture_navigation_thumb' => $this->pictureNavigationThumb,
            'picture_menu' => $this->pictureMenu,
            'picture_informations' => $this->pictureInformations,
            'NB_CATEGORIES_PAGE' => $this->nbCategoriesPage,
        ];
    }
}
