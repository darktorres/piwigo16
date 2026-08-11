<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Category\Projection\CategorySelectOptions;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable assigned by
 * {@see \Piwigo\Controller\Admin\SiteUpdateSubController::handle()}'s own
 * "introduction : choices" block. `$categoryOptions` is
 * {@see \Piwigo\Category\CategoryService}'s own
 * `category_options`/`category_options_selected` pair -- top-level keys,
 * not nested under `introduction` like the rest of this context.
 */
final readonly class SiteUpdateIntroductionPageContext implements TemplatePageContext
{
    /**
     * @param array<array-key, string> $privacyLevelOptions
     */
    public function __construct(
        public string $sync,
        public bool $syncMeta,
        public bool $displayInfo,
        public bool $addToCaddie,
        public bool $subcatsIncluded,
        public int $privacyLevelSelected,
        public bool $metaAll,
        public bool $metaEmptyOverrides,
        public array $privacyLevelOptions,
        public CategorySelectOptions $categoryOptions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'introduction' => [
                'sync' => $this->sync,
                'sync_meta' => $this->syncMeta,
                'display_info' => $this->displayInfo,
                'add_to_caddie' => $this->addToCaddie,
                'subcats_included' => $this->subcatsIncluded,
                'privacy_level_selected' => $this->privacyLevelSelected,
                'meta_all' => $this->metaAll,
                'meta_empty_overrides' => $this->metaEmptyOverrides,
                'privacy_level_options' => $this->privacyLevelOptions,
            ],
            'category_options' => $this->categoryOptions->options,
            'category_options_selected' => $this->categoryOptions->selected,
        ];
    }
}
