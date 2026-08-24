<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Category\Projection\CategorySelectOptions;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `site_update.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\SiteUpdateSubController::handle()}.
 * `$updateResult`/`$metadataResult` are each only set once their own
 * independently-gated sync stage (dirs/files, metadata) actually ran
 * this request. `$saveError` is only set when the previous sync run
 * left images with no md5sum computed. `$footerElements` accumulates
 * across whichever sync stages ran, `null` when none did.
 * `$categoryOptions` is {@see \Piwigo\Category\CategoryService}'s own
 * `category_options`/`category_options_selected` pair.
 */
#[Template('site_update.latte')]
final readonly class SiteUpdateView implements View, HasPageAssets
{
    /**
     * @param array{NB_NEW_CATEGORIES: int, NB_DEL_CATEGORIES: int, NB_NEW_ELEMENTS: int, NB_DEL_ELEMENTS: int, NB_UPD_ELEMENTS: int, NB_ERRORS: int}|null $updateResult
     * @param array{NB_ELEMENTS_DONE: int, NB_ELEMENTS_CANDIDATES: int, NB_ERRORS: int}|null $metadataResult
     * @param list<array{ELEMENT: mixed, LABEL: string}> $syncErrors
     * @param list<array{TYPE: mixed, LABEL: mixed}> $syncErrorCaptions
     * @param list<array{ELEMENT: mixed, LABEL: mixed}> $syncInfos
     * @param array{sync: string, sync_meta: bool, display_info: bool, add_to_caddie: bool, subcats_included: bool, privacy_level_selected: int, meta_all: bool, meta_empty_overrides: bool, privacy_level_options: array<array-key, string>} $introduction
     * @param list<string>|null $footerElements
     */
    public function __construct(
        public string $siteUrl,
        public ?array $updateResult,
        public string $resultUpdateLabel,
        public ?array $metadataResult,
        public string $resultMetadataLabel,
        public string $metadataList,
        public array $syncErrors,
        public array $syncErrorCaptions,
        public array $syncInfos,
        public array $introduction,
        public CategorySelectOptions $categoryOptions,
        public string $csrfToken,
        public ?string $saveError,
        public ?array $footerElements,
    ) {}

    /**
     * `site_update.latte`'s own unconditional `{do combineScript(...)}`/
     * `{do combineCss(...)}` (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('site_update', 'themes/admin/default/js/site_update.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/admin/default/css/pages/site_update.css', id: 'site_update'),
        ];
    }
}
