<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\GalleryController::__invoke()} after its
 * `assignVarFromHandle('SELECTED_TAGS_TEMPLATE', 'selected_tags')`
 * call -- see {@see GalleryPageContext} for what it assigns before that
 * point. `$searchInSetButton`/`$searchInSetAction`/`$searchInSetUrl`/
 * `$combinableTags` are this controller's "viewing a tag" variant of
 * {@see GalleryPageContext}'s own "viewing one category" trio (mutually
 * exclusive in practice; each context independently gates its own copy,
 * preserving the original's last-write-wins behavior on the rare case
 * both conditions are true). `$noSearchResults` is set from either of
 * 2 mutually-exclusive branches now (a real single-element-list literal
 * in the "zero results" branch, or the `array_map()`'d unmatched-terms
 * list in the other) -- both funnel into this same field, no more
 * separate raw `Template::append()` call. `$tagSearchResults`/
 * `$imageDerivatives` are always included (even empty) since
 * `index.tpl` reads both with `{if !empty(...)}`, not `isset()`.
 */
final readonly class GalleryThumbnailsPageContext implements TemplatePageContext
{
    /**
     * @param list<array<string, mixed>>|null $combinableTags
     * @param list<string>|null $categorySearchResults
     * @param list<string>|null $noSearchResults
     * @param array<int, array<string, mixed>>|null $imageOrders
     * @param list<array<string, mixed>>|null $relatedTags
     * @param list<array<string, mixed>> $tagSearchResults
     * @param list<array{DISPLAY: string, URL: string, SELECTED: bool}> $imageDerivatives
     */
    public function __construct(
        public ?bool $searchInSetButton,
        public ?string $searchInSetAction,
        public ?string $searchInSetUrl,
        public ?array $combinableTags,
        public ?string $uEdit,
        public ?string $uCaddie,
        public ?array $categorySearchResults,
        public ?array $noSearchResults,
        public ?array $imageOrders,
        public ?string $contentDescription,
        public ?string $uSlideshow,
        public ?bool $relatedTagsAction,
        public ?array $relatedTags,
        public array $tagSearchResults,
        public array $imageDerivatives,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'tag_search_results' => $this->tagSearchResults,
            'image_derivatives' => $this->imageDerivatives,
        ];

        if ($this->searchInSetButton !== null) {
            $result['SEARCH_IN_SET_BUTTON'] = $this->searchInSetButton;
            $result['SEARCH_IN_SET_ACTION'] = $this->searchInSetAction;
            $result['SEARCH_IN_SET_URL'] = $this->searchInSetUrl;
            $result['COMBINABLE_TAGS'] = $this->combinableTags;
        }

        if ($this->uEdit !== null) {
            $result['U_EDIT'] = $this->uEdit;
        }

        if ($this->uCaddie !== null) {
            $result['U_CADDIE'] = $this->uCaddie;
        }

        if ($this->categorySearchResults !== null) {
            $result['category_search_results'] = $this->categorySearchResults;
        }

        if ($this->noSearchResults !== null) {
            $result['no_search_results'] = $this->noSearchResults;
        }

        if ($this->imageOrders !== null) {
            $result['image_orders'] = $this->imageOrders;
        }

        if ($this->contentDescription !== null) {
            $result['CONTENT_DESCRIPTION'] = $this->contentDescription;
        }

        if ($this->uSlideshow !== null) {
            $result['U_SLIDESHOW'] = $this->uSlideshow;
        }

        if ($this->relatedTagsAction !== null) {
            $result['RELATED_TAGS_ACTION'] = $this->relatedTagsAction;
            $result['RELATED_TAGS'] = $this->relatedTags;
        }

        return $result;
    }
}
