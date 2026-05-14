<?php

declare(strict_types=1);

namespace Piwigo\Section;

/**
 * Immutable value object holding the section/navigation context for the current
 * gallery request. Built once by SectionInitializer::initialize() and
 * distributed via SectionContextRegistry.
 */
final class SectionContext
{
    /**
     * @param list<string>        $items            Image IDs in display order
     * @param list<mixed>         $tags             Tag rows (each element is array<string,mixed>)
     * @param list<int>           $tagIds
     * @param list<string>        $list             Explicit image ID list (section=list)
     * @param array<mixed>|null   $category         Current category row
     * @param list<mixed>|null    $combinedCategories (each element is array<string,mixed>)
     * @param array<mixed>        $searchDetails
     * @param array<mixed>        $qsearchDetails
     * @param list<string>        $whereClauses
     * @param list<int|string>    $chronologyDate
     */
    public function __construct(
        public readonly string  $section            = 'categories',
        public readonly string  $sectionUrl         = '',
        public readonly string  $rootPath           = '',
        public readonly array   $items              = [],
        public readonly int     $start              = 0,
        public readonly int     $startcat           = 0,
        public readonly int     $nbImagePage        = 0,
        public readonly bool    $flat               = false,
        public readonly bool    $isHomepage         = false,
        public readonly bool    $superOrderBy       = false,
        public readonly ?string $imageId            = null,
        public readonly string  $imageFile          = '',
        public readonly ?array  $category           = null,
        public readonly ?array  $combinedCategories = null,
        public readonly array   $tags               = [],
        public readonly array   $tagIds             = [],
        public readonly array   $list               = [],
        public readonly ?string $search             = null,
        public readonly ?string $searchId           = null,
        public readonly array   $searchDetails      = [],
        public readonly array   $qsearchDetails     = [],
        public readonly array   $whereClauses       = [],
        public readonly bool    $useRegexpICU       = false,
        public readonly array   $chronologyDate     = [],
        public readonly string  $chronologyField    = '',
        public readonly string  $chronologyView     = '',
        public readonly string  $chronologyStyle    = '',
        public readonly string  $title              = '',
        public readonly string  $comment            = '',
        public readonly string  $sectionTitle       = '',
        public readonly string  $feed               = '',
        public readonly bool    $isExternal         = false,
    ) {
    }

    /**
     * Converts the context back to the snake_case array format expected by
     * UrlService's URL-building methods.
     *
     * @return array<string, mixed>
     */
    public function toUrlParams(): array
    {
        $params = ['section' => $this->section];

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
        if ($this->imageFile !== '') {
            $params['image_file'] = $this->imageFile;
        }
        if ($this->category !== null) {
            $params['category'] = $this->category;
        }
        if ($this->combinedCategories !== null) {
            $params['combined_categories'] = $this->combinedCategories;
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
        if ($this->searchId !== null) {
            $params['search_id'] = $this->searchId;
        }
        if ($this->searchDetails !== []) {
            $params['search_details'] = $this->searchDetails;
        }
        if ($this->qsearchDetails !== []) {
            $params['qsearch_details'] = $this->qsearchDetails;
        }
        if ($this->whereClauses !== []) {
            $params['where_clauses'] = $this->whereClauses;
        }
        if ($this->useRegexpICU) {
            $params['use_regexp_icu'] = true;
        }
        if ($this->chronologyDate !== []) {
            $params['chronology_date'] = $this->chronologyDate;
        }
        if ($this->chronologyField !== '') {
            $params['chronology_field'] = $this->chronologyField;
        }
        if ($this->chronologyView !== '') {
            $params['chronology_view'] = $this->chronologyView;
        }
        if ($this->chronologyStyle !== '') {
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
        if ($this->feed !== '') {
            $params['feed'] = $this->feed;
        }
        if ($this->isExternal) {
            $params['is_external'] = true;
        }

        return $params;
    }
}
