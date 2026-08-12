<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;
use Piwigo\Image\DerivativeParams;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\BatchManagerGlobalPageRenderer::render()}.
 * `$associatedTags`, `$navbar`, and `$thumbParams` are genuinely
 * optional -- the original code only ever assigns those template keys
 * inside its own `count($cat_elements_id) > 0` branch, omitted here (not
 * present as a null value) to match that exact original behavior.
 * `$thumbnails` is always included (even empty) since
 * `batch_manager_global.latte` reads it with `{if !empty($thumbnails)}`,
 * not `isset()`.
 */
final readonly class BatchManagerGlobalPageContext implements TemplatePageContext
{
    /**
     * @param list<array{id: int, name: string, url_name: string, lastmodified: string, counter: int}>|null $associatedTags
     * @param array<array-key, string> $levelOptions
     * @param array<string, string> $delDerivativesTypes
     * @param array<string, string> $generateDerivativesTypes
     * @param array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int}|null $navbar
     * @param array<array-key, string> $cacheKeys
     * @param list<array<string, mixed>> $thumbnails
     */
    public function __construct(
        public bool $inCaddie,
        public ?array $associatedTags,
        public string $dateCreation,
        public array $levelOptions,
        public int $levelOptionsSelected,
        public string $usedMetadata,
        public array $delDerivativesTypes,
        public array $generateDerivativesTypes,
        public ?array $navbar,
        public ?DerivativeParams $thumbParams,
        public int $nbThumbsPage,
        public int $nbThumbsSet,
        public array $cacheKeys,
        public array $thumbnails,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'IN_CADDIE' => $this->inCaddie,
            'DATE_CREATION' => $this->dateCreation,
            'level_options' => $this->levelOptions,
            'level_options_selected' => $this->levelOptionsSelected,
            'used_metadata' => $this->usedMetadata,
            'del_derivatives_types' => $this->delDerivativesTypes,
            'generate_derivatives_types' => $this->generateDerivativesTypes,
            'nb_thumbs_page' => $this->nbThumbsPage,
            'nb_thumbs_set' => $this->nbThumbsSet,
            'CACHE_KEYS' => $this->cacheKeys,
            'thumbnails' => $this->thumbnails,
        ];

        if ($this->associatedTags !== null) {
            $result['associated_tags'] = $this->associatedTags;
        }

        if ($this->navbar !== null) {
            $result['navbar'] = $this->navbar;
        }

        if ($this->thumbParams instanceof DerivativeParams) {
            $result['thumb_params'] = $this->thumbParams;
        }

        return $result;
    }
}
