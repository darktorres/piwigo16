<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned directly by
 * {@see \Piwigo\Search\SearchFilterRenderer::render()} (its own assigns,
 * not the 4 private per-filter helpers it calls -- see
 * {@see SearchDateFilterPageContext}, {@see SearchAlbumsFoundPageContext}
 * and {@see SearchTagsFoundPageContext} for those). Every optional field
 * here is genuinely optional -- the original code only ever assigns each
 * of these template keys under its own runtime condition (a filter's own
 * `access`/`isset($searchFields[...])` gate), omitted here (not present
 * as a null value) to match that exact original behavior.
 */
final readonly class SearchFilterPageContext implements TemplatePageContext
{
    /**
     * @param array<string, array<string, mixed>> $displayFilter
     * @param list<array<array-key, mixed>>|null $tags
     * @param list<array<array-key, mixed>>|null $authors
     * @param list<array<array-key, mixed>>|null $addedBy
     * @param array<array-key, mixed>|null $filetypes
     * @param array<array-key, mixed>|null $rating
     * @param array<string, mixed>|null $filesize
     * @param array<array-key, mixed>|null $ratios
     * @param array<string, mixed>|null $height
     * @param array<string, mixed>|null $width
     */
    public function __construct(
        public array $displayFilter,
        public ?array $tags,
        public ?array $authors,
        public ?array $addedBy,
        public string|false|null $fullnameOf,
        public ?array $filetypes,
        public bool $showFilterRatings,
        public ?array $rating,
        public ?array $filesize,
        public ?array $ratios,
        public ?array $height,
        public ?array $width,
        public string|false $gp,
        public ?string $searchId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'display_filter' => $this->displayFilter,
            'SHOW_FILTER_RATINGS' => $this->showFilterRatings,
            'GP' => $this->gp,
            'SEARCH_ID' => $this->searchId,
        ];

        if ($this->tags !== null) {
            $result['TAGS'] = $this->tags;
        }

        if ($this->authors !== null) {
            $result['AUTHORS'] = $this->authors;
        }

        if ($this->addedBy !== null) {
            $result['ADDED_BY'] = $this->addedBy;
        }

        if ($this->fullnameOf !== null) {
            $result['fullname_of'] = $this->fullnameOf;
        }

        if ($this->filetypes !== null) {
            $result['FILETYPES'] = $this->filetypes;
        }

        if ($this->rating !== null) {
            $result['RATING'] = $this->rating;
        }

        if ($this->filesize !== null) {
            $result['FILESIZE'] = $this->filesize;
        }

        if ($this->ratios !== null) {
            $result['RATIOS'] = $this->ratios;
        }

        if ($this->height !== null) {
            $result['HEIGHT'] = $this->height;
        }

        if ($this->width !== null) {
            $result['WIDTH'] = $this->width;
        }

        return $result;
    }
}
