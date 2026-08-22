<?php

declare(strict_types=1);

namespace Piwigo\Controller\Event;

/**
 * Typed event for the legacy `loc_end_index` notification -- no handler
 * registered anywhere today, but no longer bare. All 3 fields are null
 * unless the current gallery view is a single real category (mirrors
 * `Section\SectionContext::$category`'s own null-when-not-applicable
 * shape: null on a flat/tag/search/homepage view). Real caller this was
 * added for: a plugin injecting an album-page-specific toolbar (e.g. via
 * `Template::concat()` onto a real, already-rendered Html-typed template
 * var) needs to know which category is being viewed (and its
 * name/comment, to prefill an edit panel -- unlike the picture-page
 * equivalent, no single-category `GET /api/v1/categories/{id}` endpoint
 * exists to fetch this client-side instead) -- `PageHeaderRendered`
 * itself fires too early (from the shared header, before this
 * controller populates `Section\SectionContext`) to carry it.
 */
final readonly class IndexRendered
{
    public function __construct(
        public ?int $categoryId = null,
        public ?string $categoryName = null,
        public ?string $categoryComment = null,
    ) {}
}
