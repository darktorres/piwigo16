<?php

declare(strict_types=1);

namespace Piwigo\Section;

use Piwigo\Category\Projection\CategoryInfo;
use Piwigo\Common\Enum\Section;

/**
 * Immutable value object holding the full gallery-navigation context for
 * the current request -- built once by SectionPopulator::populate() and
 * distributed via SectionContextRegistry.
 *
 * root_path/section_url/image_id/image_file are carried over unchanged
 * from the SectionUrlParse step (SectionInitializer::parse()).
 */
final readonly class SectionContext
{
    /**
     * @param list<int|string> $items image ids in display order
     * @param list<CategoryInfo>|null $combinedCategories
     * @param list<array<string, mixed>> $tags tag rows
     * @param list<int> $tagIds
     * @param list<int|string> $list explicit image id list (section=list)
     * @param array<string, mixed> $searchDetails
     * @param array<string, mixed> $qsearchDetails
     * @param list<int|string> $chronologyDate
     */
    public function __construct(
        public Section $section = Section::Categories,
        public string $sectionUrl = '',
        public string $rootPath = '',
        public int|string|null $imageId = null,
        public ?string $imageFile = null,
        public array $items = [],
        public int $start = 0,
        public int $startcat = 0,
        public int $nbImagePage = 0,
        public bool $flat = false,
        public bool $isHomepage = false,
        public bool $superOrderBy = false,
        public ?CategoryInfo $category = null,
        public ?array $combinedCategories = null,
        public array $tags = [],
        public array $tagIds = [],
        public array $list = [],
        public ?string $search = null,
        public array $searchDetails = [],
        public array $qsearchDetails = [],
        public array $chronologyDate = [],
        public ?string $chronologyField = null,
        public ?string $chronologyView = null,
        public ?string $chronologyStyle = null,
        public string $title = '',
        public string $comment = '',
        // Stays a plain string (not Html) despite always being genuinely
        // safe pre-formed HTML (SectionPopulator's own breadcrumb builder
        // concatenates Home/section anchor links with Lang::t()-translated
        // labels and a config-level separator, never user data) --
        // toUrlParams() below round-trips this same value back into a URL
        // param for a later parseSectionUrl() re-parse, which needs a plain
        // string. Its own two template-facing consumers
        // (Controller\Projection\IndexView::$title,
        // Controller\Projection\PictureView::$sectionTitle) wrap it in Html
        // at their own construction site instead (P59 Batch 4).
        public string $sectionTitle = '',
    ) {}

    /**
     * PictureController's own rare fallback (an image accessed by a URL
     * whose section didn't already include it, e.g. best_rated) appends
     * to the item list after this object was already built and
     * registered -- re-registering a copy with the updated list (instead
     * of just fixing PictureController's own local variable) keeps
     * downstream readers of SectionContextRegistry::current() (e.g.
     * MenubarRenderer's related-categories block) in sync for the rest
     * of the request.
     *
     * @param list<int|string> $items
     */
    public function withItems(array $items): self
    {
        return new self(
            section: $this->section,
            sectionUrl: $this->sectionUrl,
            rootPath: $this->rootPath,
            imageId: $this->imageId,
            imageFile: $this->imageFile,
            items: $items,
            start: $this->start,
            startcat: $this->startcat,
            nbImagePage: $this->nbImagePage,
            flat: $this->flat,
            isHomepage: $this->isHomepage,
            superOrderBy: $this->superOrderBy,
            category: $this->category,
            combinedCategories: $this->combinedCategories,
            tags: $this->tags,
            tagIds: $this->tagIds,
            list: $this->list,
            search: $this->search,
            searchDetails: $this->searchDetails,
            qsearchDetails: $this->qsearchDetails,
            chronologyDate: $this->chronologyDate,
            chronologyField: $this->chronologyField,
            chronologyView: $this->chronologyView,
            chronologyStyle: $this->chronologyStyle,
            title: $this->title,
            comment: $this->comment,
            sectionTitle: $this->sectionTitle,
        );
    }

    /**
     * Serializes this object back to the snake_case $page-shaped array
     * UrlService::paramsForDuplication() needs to build "duplicate the
     * current URL with these params redefined/removed" links. `section`
     * is always included; every other field is included only when it
     * differs from this class's own default -- a key is only present when
     * something actually set it, never present-but-default-valued.
     *
     * @return array<string, mixed>
     */
    public function toUrlParams(): array
    {
        $params = [
            'section' => $this->section->value,
        ];

        if ($this->sectionUrl !== '') {
            $params['section_url'] = $this->sectionUrl;
        }
        if ($this->rootPath !== '') {
            $params['root_path'] = $this->rootPath;
        }
        if ($this->items !== []) {
            $params['items'] = $this->items;
        }
        if ($this->start !== 0) {
            $params['start'] = $this->start;
        }
        if ($this->startcat !== 0) {
            $params['startcat'] = $this->startcat;
        }
        if ($this->nbImagePage !== 0) {
            $params['nb_image_page'] = $this->nbImagePage;
        }
        if ($this->flat) {
            $params['flat'] = true;
        }
        if ($this->isHomepage) {
            $params['is_homepage'] = true;
        }
        if ($this->superOrderBy) {
            $params['super_order_by'] = true;
        }
        if ($this->imageId !== null) {
            $params['image_id'] = $this->imageId;
        }
        if ($this->imageFile !== null) {
            $params['image_file'] = $this->imageFile;
        }
        if ($this->category !== null) {
            $params['category'] = $this->category->toArray();
        }
        if ($this->combinedCategories !== null) {
            $params['combined_categories'] = array_map(
                static fn (CategoryInfo $category): array => $category->toArray(),
                $this->combinedCategories
            );
        }
        if ($this->tags !== []) {
            $params['tags'] = $this->tags;
        }
        if ($this->tagIds !== []) {
            $params['tag_ids'] = $this->tagIds;
        }
        if ($this->list !== []) {
            $params['list'] = $this->list;
        }
        if ($this->search !== null) {
            $params['search'] = $this->search;
        }
        if ($this->searchDetails !== []) {
            $params['search_details'] = $this->searchDetails;
        }
        if ($this->qsearchDetails !== []) {
            $params['qsearch_details'] = $this->qsearchDetails;
        }
        if ($this->chronologyDate !== []) {
            $params['chronology_date'] = $this->chronologyDate;
        }
        if ($this->chronologyField !== null) {
            $params['chronology_field'] = $this->chronologyField;
        }
        if ($this->chronologyView !== null) {
            $params['chronology_view'] = $this->chronologyView;
        }
        if ($this->chronologyStyle !== null) {
            $params['chronology_style'] = $this->chronologyStyle;
        }
        if ($this->title !== '') {
            $params['title'] = $this->title;
        }
        if ($this->comment !== '') {
            $params['comment'] = $this->comment;
        }
        if ($this->sectionTitle !== '') {
            $params['section_title'] = $this->sectionTitle;
        }

        return $params;
    }
}
