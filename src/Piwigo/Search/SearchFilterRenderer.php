<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Auth\AccessControl;
use Piwigo\Category\CategoryRepository;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\TemplateInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\Tables;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
use Piwigo\Section\SectionContext;
use Piwigo\Tag\TagService;

/**
 * Renders the search-refinement sidebar (author/tags/date_posted/
 * date_created/added_by/album/filetypes/ratings/filesize/ratios/height/
 * width filters) plus the "ALBUMS_FOUND"/"TAGS_FOUND" search-hint block.
 * Ported from include/search_filters.inc.php -- structurally a mechanical
 * port (single complete `global` block, every local variable traced to
 * confirm none reads an undeclared outer scope), except for one real fix:
 * see the ALBUMS_FOUND block below.
 *
 * Gap-closure Stage 4a (docs/plan/gap-closure-p0-p23.md): the several
 * per-filter row/count caches below now go through
 * {@see \Piwigo\Cache\CachePools::searchResults()} (30s TTL) instead of the
 * older `PersistentCache`/`cacheUpdateTime`-keyed mechanism this class's
 * docblock previously kept deliberately out of scope.
 *
 * Every `mixed` below stays that way by design: cacheGet()/cacheSet() are
 * a generic PSR-6 cache-pool wrapper (arbitrary cached value, same
 * rationale as Cache\PersistentCache); every `$page` param is this file's
 * own "plain local array shaped like the old global $page" (see this
 * method's own docblock below) -- a genuinely heterogeneous bag (section/
 * category/chronology/cat/tags/... all mixed together), not a single
 * reusable domain shape.
 */
final readonly class SearchFilterRenderer
{
    public function __construct(
        private AccessControl $accessControl,
        private HtmlRenderingInterface $htmlRenderer,
        private TemplateInterface $template,
        private SearchRepository $repo,
        private SearchService $searchService,
        private TagService $tagService,
        private CategoryRepository $categoryRepo,
        private PermissionService $permissionService,
        private UrlServiceInterface $urlService,
        private \Piwigo\Core\CurrentLogger $currentLogger,
        private \Piwigo\Users\CurrentUser $currentUser,
    ) {}

    private function cacheGet(string $key): mixed
    {
        $item = \Piwigo\Cache\CachePools::searchResults()->getItem($key);

        return $item->isHit() ? $item->get() : null;
    }

    private function cacheSet(string $key, mixed $value): void
    {
        $pool = \Piwigo\Cache\CachePools::searchResults();
        $item = $pool->getItem($key);
        $item->set($value);
        $pool->save($item);
    }

    /**
     * Legacy Coupling Retirement Track A batch A5.2e: $sectionContext is an
     * explicit param instead of `global $page;`. The rest of this method
     * (and every private helper it calls) keeps treating `$page` as a
     * plain local array shaped like the old global -- it's built once
     * here from $sectionContext's own fields and mutates
     * 'search_details' in place exactly as before, just no longer visible
     * outside this call (nothing downstream ever read the mutated
     * version).
     *
     * Batch A5.2h: returns the search id resolved by
     * SearchService::getValidatedSearchArray() (null when this page isn't
     * a search results page), replacing the former
     * `$page['search_id'] = ...` write -- the one real caller
     * (GalleryController) passes it straight into its own
     * HistoryService::logVisit() call.
     */
    public function render(SectionContext $sectionContext): ?int
    {
        $page = [
            'section' => $sectionContext->section,
            'search' => $sectionContext->search,
            'search_details' => $sectionContext->searchDetails,
            'items' => $sectionContext->items,
            'start' => $sectionContext->start,
            'chronology_field' => $sectionContext->chronologyField,
        ];
        $template = $this->template;

        $tagService = $this->tagService;

        $filtersViewsRaw = \Piwigo\Config\CurrentConfig::filtersViews() ?? \Piwigo\Config\CurrentConfig::defaultFiltersViews();

        // 'last_filters_conf' is a lone boolean flag stored alongside the
        // per-filter settings in this config value (see
        // admin/configuration.php); every other entry is a settings array.
        // This method only ever reads the per-filter arrays by name, so
        // drop the flag here to give $filtersViews a uniform, narrow shape.
        /** @var array<string, array<string, mixed>> $filtersViews */
        $filtersViews = array_filter($filtersViewsRaw, is_array(...));

        $template->assign('display_filter', $filtersViews);

        // we add the search_details emptiness check in this condition
        // because it only applies to regular search, not the legacy
        // qsearch (SectionContext::searchDetails stays [] whenever
        // SectionPopulator's own search branch took the qsearch path
        // instead -- see that class's search-section handling). As
        // Piwigo 14 will still be able to show an old quicksearch
        // result, we must check this condition too.
        if ($page['section'] !== 'search' || $page['search_details'] === []) {
            return null;
        }

        $displayFilters = $filtersViews;

        foreach ($filtersViews as $filtName => $filtConf) {
            if (isset($filtConf['access'])) {
                if ($filtConf['access'] === 'everybody' or ($filtConf['access'] === 'admins-only' and $this->accessControl->isAdmin()) or ($filtConf['access'] === 'registered-users' and $this->accessControl->isClassicUser())) {
                    $displayFilters[$filtName]['access'] = true;
                } else {
                    $displayFilters[$filtName]['access'] = false;
                }
            }
        }

        $userId = (string) $this->currentUser->get()
            ->id->value;

        $langMonth = \Piwigo\Core\Lang::months();

        $searchId = $page['search'] ?? '';
        $resolvedSearchId = null;
        $mySearch = $this->searchService->getValidatedSearchArray($searchId, $sectionContext->section, $resolvedSearchId);
        if (! is_array($mySearch)) {
            // getValidatedSearchArray() only returns false when a search
            // row's `rules` is malformed/null; this method only runs for an
            // already-validated search (get_search_info() calls
            // bad_request() otherwise), so this is just a defensive
            // fallback keeping the rest of this method array-typed.
            $mySearch = [];
        }
        if (! isset($mySearch['fields']) || ! is_array($mySearch['fields'])) {
            $mySearch['fields'] = [];
        }

        /** @var array<string, mixed> $searchFields */
        $searchFields = &$mySearch['fields'];

        // SQL-modernization audit: 'forbidden' used to hold a raw,
        // already-'\n  AND '-prefixed string (getSqlConditionFandF()) --
        // now a SqlCondition (getClauseForFilter() below combines it via
        // SqlCondition::combine() instead of string concatenation, so no
        // prefix is needed). Confirmed via direct grep this key is never
        // read outside this file.
        $page['search_details']['forbidden'] = $this->permissionService->getSqlConditionFandFAsCondition([
            'forbidden_categories' => 'category_id',
            'visible_categories' => 'category_id',
            'visible_images' => 'id',
        ]);

        // we want filters to be filled with values related to current items
        // ONLY IF we have some filters filled
        if ((bool) $page['search_details']['has_filters_filled']) {
            $searchItems = [-1];
            if ($page['items'] !== []) {
                $searchItems = $page['items'];
            }

            $searchItemsClause = 'image_id IN (' . implode(',', $searchItems) . ')';
        } else {
            $searchItemsClause = '1=1';
        }
        unset($searchItemsClause); // kept for parity with the original's own unused local; not referenced downstream

        if (isset($searchFields['allwords']) and ! ((bool) $displayFilters['words']['access'])) {
            unset($searchFields['allwords']);
        }

        if (isset($searchFields['tags']) and (bool) $displayFilters['tags']['access']) {
            $filterTags = [];

            // Known limitation: TagService::getAvailableTags() below isn't
            // cached/reused across this request even though it may already
            // have run once for other purposes (e.g. building the menu) --
            // a real but non-blocking optimization opportunity on
            // large-gallery installs, not a defect.
            if (! is_array($searchFields['tags'])) {
                $searchFields['tags'] = [];
            }

            $tagWords = [];
            if (is_array($searchFields['tags']['words'] ?? null)) {
                foreach ($searchFields['tags']['words'] as $tagWord) {
                    if (is_int($tagWord) || is_string($tagWord)) {
                        $tagWords[] = $tagWord;
                    }
                }
            }

            /**
             * @param array<int, array<string, mixed>> $tags
             * @return list<int|string>
             */
            $extractTagIds = static function (array $tags): array {
                $ids = [];
                foreach ($tags as $tag) {
                    if (! is_array($tag)) {
                        continue;
                    }
                    $tagId = $tag['id'] ?? null;
                    if (is_int($tagId) || is_string($tagId)) {
                        $ids[] = $tagId;
                    }
                }
                return $ids;
            };

            $otherFiltersItems = $this->getItemsForFilter('tags', $page);
            if ($otherFiltersItems === false) {
                $filterTags = $tagService->getAvailableTags();
                usort($filterTags, $this->htmlRenderer->tagAlphaCompare(...));
            } else {
                $tagFilterItems = [];
                foreach ($otherFiltersItems as $otherFilterItem) {
                    if (is_numeric($otherFilterItem)) {
                        $tagFilterItems[] = (int) $otherFilterItem;
                    }
                }

                $filterTags = $tagService->getCommonTags($tagFilterItems, 0, $this->htmlRenderer);

                // the user may have started a search on 2 or more tags that
                // have no intersection. In this case, $searchItems is empty
                // and TagService::getCommonTags() returns nothing. We should still
                // display the list of selected tags. We have to "force"
                // them in the list.
                $missingTagIds = array_diff($tagWords, $extractTagIds($filterTags));

                if (count($missingTagIds) > 0) {
                    $filterTags = array_merge($tagService->getAvailableTags($missingTagIds), $filterTags);
                }
            }

            $template->assign('TAGS', $filterTags);

            $filterTagIds = count($filterTags) > 0 ? $extractTagIds($filterTags) : [];

            // in case the search has forbidden tags for current user, we
            // need to filter the search rule
            $searchFields['tags']['words'] = array_intersect($tagWords, $filterTagIds);
        } elseif (isset($searchFields['tags'])) {
            unset($searchFields['tags']);
        }

        if (isset($searchFields['expert'])) {
            if (! (bool) $displayFilters['expert']['access']) {
                unset($searchFields['expert']);
            } else {
                Lang::load('help_quick_search.lang');
            }
        }

        if (isset($searchFields['author']) and (bool) $displayFilters['author']['access']) {
            $filterCondition = $this->getClauseForFilter('author', $page);

            $imagesTable = Tables::images();
            $imageCategoryTable = Tables::imageCategory();
            $query = <<<SQL
                SELECT
                    author,
                    COUNT(DISTINCT(id)) AS counter
                FROM {$imagesTable} AS i
                    JOIN {$imageCategoryTable} AS ic ON ic.image_id = i.id
                WHERE {$filterCondition->sql}
                    AND author IS NOT NULL
                GROUP BY author
                SQL;

            if (! str_starts_with($filterCondition->sql, 'image_id IN')) {
                // we use the cache pool only for fetching lines filtered
                // only by permissions
                $cacheKey = 'author_rows_' . $userId;
                $filterRows = $this->cacheGet($cacheKey);
                if (! is_array($filterRows)) {
                    $filterRows = $this->repo->queryRows($query, $filterCondition->parameters, $filterCondition->types);
                    $this->cacheSet($cacheKey, $filterRows);
                }
            } else {
                $filterRows = $this->repo->queryRows($query, $filterCondition->parameters, $filterCondition->types);
            }

            // the cache pool stores this row set as plain mixed data, so
            // validate each row's shape defensively rather than trusting it.
            $authorNames = [];
            $authors = [];
            foreach ($filterRows as $authorRow) {
                if (! is_array($authorRow)) {
                    continue;
                }
                $authors[] = $authorRow;

                $authorName = $authorRow['author'] ?? null;
                if (is_string($authorName)) {
                    $authorNames[] = $authorName;
                }
            }
            $template->assign('AUTHORS', $authors);

            if (! is_array($searchFields['author'])) {
                $searchFields['author'] = [];
            }

            $authorWords = [];
            if (is_array($searchFields['author']['words'] ?? null)) {
                foreach ($searchFields['author']['words'] as $authorWord) {
                    if (is_string($authorWord)) {
                        $authorWords[] = $authorWord;
                    }
                }
            }

            // in case the search has forbidden authors for current user, we
            // need to filter the search rule
            $searchFields['author']['words'] = array_intersect($authorWords, $authorNames);
        } elseif (isset($searchFields['author'])) {
            unset($searchFields['author']);
        }

        if (isset($searchFields['date_posted']) and (bool) $displayFilters['post_date']['access']) {
            $this->renderDateFilter(
                $langMonth,
                $userId,
                'date_posted',
                'date_available',
                [
                    '24h' => Lang::t('last 24 hours'),
                    '7d' => Lang::t('last 7 days'),
                    '30d' => Lang::t('last 30 days'),
                    '3m' => Lang::t('last 3 months'),
                    '6m' => Lang::t('last 6 months'),
                ],
                'LIST_DATE_POSTED',
                'DATE_POSTED',
                $template,
                $page
            );
        } elseif (isset($searchFields['date_posted'])) {
            unset($searchFields['date_posted']);
        }

        if (isset($searchFields['date_created']) and (bool) $displayFilters['creation_date']['access']) {
            $this->renderDateFilter(
                $langMonth,
                $userId,
                'date_created',
                'date_creation',
                [
                    '7d' => Lang::t('last 7 days'),
                    '30d' => Lang::t('last 30 days'),
                    '3m' => Lang::t('last 3 months'),
                    '6m' => Lang::t('last 6 months'),
                    '12m' => Lang::t('last 12 months'),
                ],
                'LIST_DATE_CREATED',
                'DATE_CREATED',
                $template,
                $page
            );
        } elseif (isset($searchFields['date_created'])) {
            unset($searchFields['date_created']);
        }

        if (isset($searchFields['added_by']) and (bool) $displayFilters['added_by']['access']) {
            $filterCondition = $this->getClauseForFilter('added_by', $page);

            $imagesTable = Tables::images();
            $imageCategoryTable = Tables::imageCategory();
            $query = <<<SQL
                SELECT
                    COUNT(DISTINCT(id)) AS counter,
                    added_by AS added_by_id
                FROM {$imagesTable} AS i
                    JOIN {$imageCategoryTable} AS ic ON ic.image_id = i.id
                WHERE {$filterCondition->sql}
                GROUP BY added_by_id
                ORDER BY counter DESC
                SQL;

            if (! str_starts_with($filterCondition->sql, 'image_id IN')) {
                // we use the cache pool only for fetching lines filtered
                // only by permissions
                $cacheKey = 'added_by_rows_' . $userId;
                $filterRows = $this->cacheGet($cacheKey);
                if (! is_array($filterRows)) {
                    $filterRows = $this->repo->queryRows($query, $filterCondition->parameters, $filterCondition->types);
                    $this->cacheSet($cacheKey, $filterRows);
                }
            } else {
                $filterRows = $this->repo->queryRows($query, $filterCondition->parameters, $filterCondition->types);
            }

            // the cache pool stores this row set as plain mixed data, so
            // validate each row's shape defensively rather than trusting it.
            $addedBy = [];
            foreach ($filterRows as $addedByRow) {
                if (is_array($addedByRow)) {
                    $addedBy[] = $addedByRow;
                }
            }

            $userIds = [];

            if (count($addedBy) > 0) {
                // now let's find the usernames of added_by users
                foreach ($addedBy as $row) {
                    $rowAddedById = $row['added_by_id'] ?? null;
                    if (is_string($rowAddedById)) {
                        $userIds[] = $rowAddedById;
                    }
                }

                $confUserFields = \Piwigo\Config\CurrentConfig::userFields();
                $userFieldId = $confUserFields['id'];
                $userFieldUsername = $confUserFields['username'];

                // SQL-modernization audit: $userIdsCsv used to be spliced
                // directly (implode() CSV) -- now bound. $userIds are
                // added_by_id column values (DB-sourced strings, not
                // request input), but converted regardless per this
                // initiative's "regardless of exploitability" scope.
                $usersTable = Tables::users();
                $userIdsInts = array_map(intval(...), $userIds);
                $query = <<<SQL
                    SELECT
                        {$userFieldId} AS id,
                        {$userFieldUsername} AS username
                    FROM {$usersTable}
                    WHERE {$userFieldId} IN (:userIds)
                    SQL;
                $usernameOf = $this->repo->queryKeyedColumn($query, 'id', 'username', [
                    'userIds' => $userIdsInts,
                ], [
                    'userIds' => ArrayParameterType::INTEGER,
                ]);

                foreach (array_keys($addedBy) as $addedByIdx) {
                    $addedById = $addedBy[$addedByIdx]['added_by_id'] ?? null;
                    if (! is_string($addedById)) {
                        continue;
                    }
                    $addedBy[$addedByIdx]['added_by_name'] = $usernameOf[$addedById] ?? 'user #' . $addedById . ' (deleted)';
                }
            }

            $template->assign('ADDED_BY', $addedBy);

            $addedByIds = [];
            if (is_array($searchFields['added_by'])) {
                foreach ($searchFields['added_by'] as $addedByWord) {
                    if (is_int($addedByWord) || is_string($addedByWord)) {
                        $addedByIds[] = $addedByWord;
                    }
                }
            }

            // in case the search has forbidden added_by users for current
            // user, we need to filter the search rule
            $searchFields['added_by'] = array_intersect($addedByIds, $userIds);
        } elseif (isset($searchFields['added_by'])) {
            unset($searchFields['added_by']);
        }

        if (isset($searchFields['cat']) and (bool) $displayFilters['album']['access']) {
            $catWords = [];
            if (is_array($searchFields['cat']) && is_array($searchFields['cat']['words'] ?? null)) {
                foreach ($searchFields['cat']['words'] as $catWord) {
                    if (is_int($catWord) || is_string($catWord)) {
                        $catWords[] = $catWord;
                    }
                }
            }

            if (count($catWords) > 0) {
                $fullnameOf = [];

                // Gap-closure Stage 4h (docs/plan/gap-closure-p0-p23.md):
                // the user_cache_categories INNER JOIN this visibility
                // filter used to run through had gone permanently empty
                // (Stage 4g deleted the table's only remaining writer) --
                // a live regression this fix closes, replaced with the
                // same live PermissionService condition every other
                // visibility check in this class already uses.
                //
                // SQL-modernization audit: $permissionCondition used to
                // be a raw already-prefixed string; $catWordsCsv used to
                // be spliced via implode() CSV. Both now bound, combined
                // via SqlCondition::combine().
                $permissionCondition = $this->permissionService->getSqlConditionFandFAsCondition([
                    'visible_categories' => 'id',
                ]);
                $catWordsInts = array_map(static fn (int|string $v): int => (int) $v, $catWords);
                $idsCondition = new SqlCondition(
                    'id IN (:catWords)',
                    [
                        'catWords' => $catWordsInts,
                    ],
                    [
                        'catWords' => ArrayParameterType::INTEGER,
                    ],
                );
                $combinedCondition = SqlCondition::combine('AND', $idsCondition, $permissionCondition);

                $categoriesTable = Tables::categories();
                $query = <<<SQL
                    SELECT
                        id,
                        uppercats
                    FROM {$categoriesTable}
                    WHERE {$combinedCondition->sql}
                    SQL;
                foreach ($this->repo->queryRows($query, $combinedCondition->parameters, $combinedCondition->types) as $row) {
                    if ($row['id'] === null || $row['uppercats'] === null) {
                        continue;
                    }

                    // The $url argument here has no observable effect: it
                    // only controls the href of the <a> tag
                    // getCatDisplayNameCache() wraps each name in, and that
                    // wrapper gets stripped by strip_tags() below either
                    // way (this array is JSON-encoded for a JS-consumed
                    // autocomplete label, not rendered as a link).
                    $catDisplayName = $this->htmlRenderer->getCatDisplayNameCache(
                        $row['uppercats'],
                        'admin.php?page=album-'
                    );
                    $row['fullname'] = strip_tags($catDisplayName);

                    $fullnameOf[$row['id']] = $row['fullname'];
                }

                $template->assign('fullname_of', json_encode($fullnameOf));

                if (! is_array($searchFields['cat'])) {
                    $searchFields['cat'] = [];
                }

                // in case the search has forbidden albums for current user,
                // we need to filter the search rule
                $searchFields['cat']['words'] = array_intersect($catWords, array_keys($fullnameOf));
            }
        } elseif (isset($searchFields['cat'])) {
            unset($searchFields['cat']);
        }

        if (isset($searchFields['filetypes']) and (bool) $displayFilters['file_type']['access']) {
            $filterCondition = $this->getClauseForFilter('filetypes', $page);

            // get all file extensions for this user in the gallery,
            // whatever the current filters
            $cacheKey = 'file_exts_' . $userId;
            // Always a SqlCondition here -- unconditionally set earlier in
            // this method, before any branching; re-narrowed because the
            // getClauseForFilter() by-ref call above widens $page's own
            // per-key types back to the generic array<string, mixed> the
            // parameter itself is typed as.
            $searchDetailsRaw = $page['search_details'];
            $searchDetailsForbiddenRaw = is_array($searchDetailsRaw) ? ($searchDetailsRaw['forbidden'] ?? null) : null;
            $searchDetailsForbidden = $searchDetailsForbiddenRaw instanceof SqlCondition ? $searchDetailsForbiddenRaw : new SqlCondition('');
            $allExtsCondition = SqlCondition::combine('AND', new SqlCondition('1=1'), $searchDetailsForbidden);
            $imagesTable = Tables::images();
            $imageCategoryTable = Tables::imageCategory();
            $allExtsQuery = <<<SQL
                SELECT
                    SUBSTRING_INDEX(path, ".", -1) AS ext,
                    COUNT(DISTINCT(id)) AS counter
                FROM {$imagesTable} AS i
                    JOIN {$imageCategoryTable} AS ic ON ic.image_id = i.id
                WHERE {$allExtsCondition->sql}
                GROUP BY ext
                ORDER BY counter DESC
                SQL;
            $allExts = $this->cacheGet($cacheKey);
            if (! is_array($allExts)) {
                $allExts = $this->repo->queryKeyedColumn($allExtsQuery, 'ext', 'counter', $allExtsCondition->parameters, $allExtsCondition->types);
                $this->cacheSet($cacheKey, $allExts);
            }

            if (str_starts_with($filterCondition->sql, 'image_id IN')) {
                $query = <<<SQL
                    SELECT
                        SUBSTRING_INDEX(path, ".", -1) AS ext,
                        COUNT(DISTINCT(id)) AS counter
                    FROM {$imagesTable} AS i
                        JOIN {$imageCategoryTable} AS ic ON ic.image_id = i.id
                    WHERE {$filterCondition->sql}
                    GROUP BY ext
                    ORDER BY counter DESC
                    SQL;
                $filteredExts = $this->repo->queryKeyedColumn($query, 'ext', 'counter', $filterCondition->parameters, $filterCondition->types);

                $exts = [];
                foreach ($allExts as $ext => $counter) {
                    $exts[$ext] = $filteredExts[$ext] ?? 0;
                }

                $template->assign('FILETYPES', $exts);
            } else {
                $template->assign('FILETYPES', $allExts);
            }
        } elseif (isset($searchFields['filetypes'])) {
            unset($searchFields['filetypes']);
        }

        // For rating
        if (\Piwigo\Config\CurrentConfig::rateEnabled()) {
            $template->assign('SHOW_FILTER_RATINGS', true);

            if (isset($searchFields['ratings']) and (bool) $displayFilters['rating']['access']) {
                $filterCondition = $this->getClauseForFilter('ratings', $page);

                $cacheKey = 'ratings_' . $userId;
                $cacheApplicable = ! str_starts_with($filterCondition->sql, 'image_id IN');
                $ratings = $cacheApplicable ? $this->cacheGet($cacheKey) : null;

                if (! is_array($ratings)) {
                    $imagesTable = Tables::images();
                    $imageCategoryTable = Tables::imageCategory();
                    $query = <<<SQL
                        SELECT
                            DISTINCT id,
                            rating_score
                        FROM {$imagesTable} AS i
                            JOIN {$imageCategoryTable} AS ic ON ic.image_id = i.id
                        WHERE {$filterCondition->sql}
                        SQL;

                    $filterRows = $this->repo->queryRows($query, $filterCondition->parameters, $filterCondition->types);

                    $ratings = array_fill(0, 6, 0);

                    foreach ($filterRows as $row) {
                        $r = 5;

                        if (! isset($row['rating_score'])) {
                            $r = 0;
                        } else {
                            for ($i = 1; $i <= 4; $i++) {
                                if (is_numeric($row['rating_score']) && (float) $row['rating_score'] < $i) {
                                    $r = $i;
                                    break;
                                }
                            }
                        }

                        $ratings[$r]++;
                    }

                    if ($cacheApplicable) {
                        // for this filter, we do not store in cache the
                        // $filterRows: for a big gallery it may take more
                        // than 10MB. It is smarter to store in cache the
                        // result of the computation, which is just around
                        // 100 bytes.
                        $this->cacheSet($cacheKey, $ratings);
                    }
                }
                $template->assign('RATING', $ratings);
            } elseif (isset($searchFields['ratings'])) {
                unset($searchFields['ratings']);
            }
        } else {
            $template->assign('SHOW_FILTER_RATINGS', false);
            if (isset($searchFields['ratings'])) {
                unset($searchFields['ratings']);
            }
        }

        // For filesize
        if (isset($searchFields['filesize_min']) && isset($searchFields['filesize_max']) and (bool) $displayFilters['file_size']['access']) {
            $filterCondition = $this->getClauseForFilter('filesize', $page);

            $filesizes = [];

            $imagesTable = Tables::images();
            $imageCategoryTable = Tables::imageCategory();
            $query = <<<SQL
                SELECT
                    DISTINCT id,
                    filesize
                FROM {$imagesTable} AS i
                    JOIN {$imageCategoryTable} AS ic ON ic.image_id = i.id
                WHERE {$filterCondition->sql}
                SQL;
            foreach ($this->repo->queryRows($query, $filterCondition->parameters, $filterCondition->types) as $row) {
                if (! is_numeric($row['filesize'])) {
                    continue;
                }
                $bucket = sprintf('%.1f', (float) $row['filesize'] / 1024.0);
                $filesizes[$bucket] = ($filesizes[$bucket] ?? 0) + 1;
            }

            if ($filesizes === []) { // arbitrary values, only used when no photos on the gallery
                $filesizes = [
                    0 => 1,
                    1 => 1,
                    2 => 1,
                    5 => 1,
                    8 => 1,
                    15 => 1,
                ];
            }

            $uniqueFilesizes = array_keys($filesizes);
            sort($uniqueFilesizes, SORT_NUMERIC);

            $filesizeMin = $searchFields['filesize_min'];
            $filesizeMax = $searchFields['filesize_max'];

            // warning: we will (hopefully) have smarter values for filters.
            // The min/max of the current search won't always be the
            // first/last values found. It's going to be a problem with
            // this way to select selected values
            $filesize = [
                'list' => implode(',', $uniqueFilesizes),
                'bounds' => [
                    'min' => $uniqueFilesizes[0],
                    'max' => end($uniqueFilesizes),
                ],
                'selected' => [
                    'min' => (is_numeric($filesizeMin) && (float) $filesizeMin !== 0.0) ? sprintf('%.1f', (float) $filesizeMin / 1024.0) : $uniqueFilesizes[0],
                    'max' => (is_numeric($filesizeMax) && (float) $filesizeMax !== 0.0) ? sprintf('%.1f', (float) $filesizeMax / 1024.0) : end($uniqueFilesizes),
                ],
            ];

            $template->assign('FILESIZE', $filesize);
        } elseif (isset($searchFields['filesize_min']) && isset($searchFields['filesize_max']) and ! ((bool) $displayFilters['file_size']['access'])) {
            unset($searchFields['filesize_min']);
            unset($searchFields['filesize_max']);
        }

        if (isset($searchFields['ratios']) and (bool) $displayFilters['ratio']['access']) {
            $filterCondition = $this->getClauseForFilter('ratios', $page);

            $cacheKey = 'ratios_' . $userId;
            $cacheApplicable = ! str_starts_with($filterCondition->sql, 'image_id IN');
            $ratios = $cacheApplicable ? $this->cacheGet($cacheKey) : null;

            if (! is_array($ratios)) {
                $imagesTable = Tables::images();
                $imageCategoryTable = Tables::imageCategory();
                $query = <<<SQL
                    SELECT
                        DISTINCT id,
                        width,
                        height
                    FROM {$imagesTable} as i
                        JOIN {$imageCategoryTable} AS ic ON ic.image_id = i.id
                    WHERE {$filterCondition->sql}
                        AND width IS NOT NULL
                        AND height IS NOT NULL
                    SQL;

                $filterRows = $this->repo->queryRows($query, $filterCondition->parameters, $filterCondition->types);

                $ratios = [
                    'Portrait' => 0,
                    'square' => 0,
                    'Landscape' => 0,
                    'Panorama' => 0,
                ];

                foreach ($filterRows as $row) {
                    if (! is_numeric($row['width']) || ! is_numeric($row['height'])) {
                        continue;
                    }

                    $rowWidth = (float) $row['width'];
                    $rowHeight = (float) $row['height'];

                    if ($rowWidth <= 0 and $rowHeight <= 0) {
                        continue;
                    }

                    $r = $rowWidth / $rowHeight;
                    if ($r < 0.95) {
                        $ratios['Portrait']++;
                    } elseif ($r >= 0.95 and $r <= 1.05) {
                        $ratios['square']++;
                    } elseif ($r > 1.05 and $r < 2) {
                        $ratios['Landscape']++;
                    } elseif ($r >= 2) {
                        $ratios['Panorama']++;
                    }
                }

                if ($cacheApplicable) {
                    // for this filter, we do not store in cache the
                    // $filterRows: for a big gallery it may take more than
                    // 10MB. It is smarter to store in cache the result of
                    // the computation, which is just around 100 bytes.
                    $this->cacheSet($cacheKey, $ratios);
                }
            }
            $template->assign('RATIOS', $ratios);
        } elseif (isset($searchFields['ratios'])) {
            unset($searchFields['ratios']);
        }

        if (isset($searchFields['height_min']) and isset($searchFields['height_max']) and (bool) $displayFilters['height']['access']) {
            $filterCondition = $this->getClauseForFilter('height', $page);

            $imagesTable = Tables::images();
            $imageCategoryTable = Tables::imageCategory();
            $query = <<<SQL
                SELECT
                    height
                FROM {$imagesTable} as i
                    JOIN {$imageCategoryTable} AS ic ON ic.image_id = i.id
                WHERE {$filterCondition->sql}
                    AND height IS NOT NULL
                GROUP BY height
                ORDER BY height ASC
                SQL;

            if (! str_starts_with($filterCondition->sql, 'image_id IN')) {
                // we use the cache pool only for fetching lines filtered
                // only by permissions
                $cacheKey = 'height_rows_' . $userId;
                $filterRows = $this->cacheGet($cacheKey);
                if (! is_array($filterRows)) {
                    $filterRows = $this->repo->queryColumn($query, 'height', $filterCondition->parameters, $filterCondition->types);
                    $this->cacheSet($cacheKey, $filterRows);
                }
            } else {
                $filterRows = $this->repo->queryColumn($query, 'height', $filterCondition->parameters, $filterCondition->types);
            }

            // the cache pool stores this row set as plain mixed data, so
            // validate each value defensively rather than trusting it.
            $heights = [];
            foreach ($filterRows as $heightValue) {
                if (is_string($heightValue)) {
                    $heights[] = $heightValue;
                }
            }

            $heightMin = $searchFields['height_min'];
            $heightMax = $searchFields['height_max'];

            $height = [
                'list' => implode(',', $heights),
                'bounds' => [
                    'min' => $heights[0] ?? null,
                    'max' => end($heights),
                ],
                'selected' => [
                    'min' => (is_scalar($heightMin) && $heightMin !== '' && $heightMin !== 0) ? $heightMin : ($heights[0] ?? null),
                    'max' => (is_scalar($heightMax) && $heightMax !== '' && $heightMax !== 0) ? $heightMax : end($heights),
                ],
            ];

            $template->assign('HEIGHT', $height);
        } elseif (isset($searchFields['height_min']) && isset($searchFields['height_max']) and ! ((bool) $displayFilters['height']['access'])) {
            unset($searchFields['height_min']);
            unset($searchFields['height_max']);
        }

        if (isset($searchFields['width_min']) and isset($searchFields['width_max']) and (bool) $displayFilters['width']['access']) {
            $filterCondition = $this->getClauseForFilter('width', $page);

            $imagesTable = Tables::images();
            $imageCategoryTable = Tables::imageCategory();
            $query = <<<SQL
                SELECT
                    width
                FROM {$imagesTable} as i
                    JOIN {$imageCategoryTable} AS ic ON ic.image_id = i.id
                WHERE {$filterCondition->sql}
                    AND width IS NOT NULL
                GROUP BY width
                ORDER BY width ASC
                SQL;

            if (! str_starts_with($filterCondition->sql, 'image_id IN')) {
                // we use the cache pool only for fetching lines filtered
                // only by permissions
                $cacheKey = 'width_rows_' . $userId;
                $filterRows = $this->cacheGet($cacheKey);
                if (! is_array($filterRows)) {
                    $filterRows = $this->repo->queryColumn($query, 'width', $filterCondition->parameters, $filterCondition->types);
                    $this->cacheSet($cacheKey, $filterRows);
                }
            } else {
                $filterRows = $this->repo->queryColumn($query, 'width', $filterCondition->parameters, $filterCondition->types);
            }

            // the cache pool stores this row set as plain mixed data, so
            // validate each value defensively rather than trusting it.
            $widths = [];
            foreach ($filterRows as $widthValue) {
                if (is_string($widthValue)) {
                    $widths[] = $widthValue;
                }
            }

            $widthMin = $searchFields['width_min'];
            $widthMax = $searchFields['width_max'];

            $width = [
                'list' => implode(',', $widths),
                'bounds' => [
                    'min' => $widths[0] ?? null,
                    'max' => end($widths),
                ],
                'selected' => [
                    'min' => (is_scalar($widthMin) && $widthMin !== '' && $widthMin !== 0) ? $widthMin : ($widths[0] ?? null),
                    'max' => (is_scalar($widthMax) && $widthMax !== '' && $widthMax !== 0) ? $widthMax : end($widths),
                ],
            ];

            $template->assign('WIDTH', $width);
        } elseif (isset($searchFields['width_min']) && isset($searchFields['width_max']) and ! ((bool) $displayFilters['width']['access'])) {
            unset($searchFields['width_min']);
            unset($searchFields['width_max']);
        }

        $template->assign(
            [
                'GP' => json_encode($mySearch),
                'SEARCH_ID' => $page['search'],
            ]
        );

        // $page['search_details'] is already known array here (guarded above).
        $pageStart = $page['start'] ?? null;
        if ((is_numeric($pageStart) ? (int) $pageStart : 0) === 0 and ! isset($page['chronology_field'])) {
            $this->renderAlbumsFound($page, $userId, $template);
            $this->renderTagsFound($page, $template);
        }

        return $resolvedSearchId;
    }

    /**
     * The "ALBUMS_FOUND" search-hint block: categories whose name/comment
     * matched the search text (SearchService::searchAllwords()'s
     * $matching_cat_ids). Real bug avoided here, not just a simplification:
     * searchAllwords()'s category-name/comment match applies no
     * forbidden-categories condition at all (confirmed by reading it) --
     * $cat_ids can genuinely contain a category this user can't see, so the
     * original file's own `user_cache_categories` JOIN was the real,
     * load-bearing permission filter, not redundant belt-and-suspenders.
     * Ported as a plain PHP existence-filter against
     * CurrentUser::get()->forbiddenCategories (same concept as batch 3a)
     * instead of that JOIN, then CategoryRepository::findFullCategoriesByIds()
     * (batch 4b) for the row data.
     *
     * @param array<string, mixed> $page
     */
    private function renderAlbumsFound(array $page, string $userId, TemplateInterface $template): void
    {
        $searchDetails = $page['search_details'] ?? null;
        $matchingCatIds = is_array($searchDetails) ? ($searchDetails['matching_cat_ids'] ?? null) : null;
        if (! is_array($matchingCatIds)) {
            return;
        }

        // shape from SearchService::getRegularSearchResults(): list<int>
        // (ids come back as native int under this project's mysqli driver
        // config, not the string ids the pre-port procedural code
        // returned) -- is_int()||is_string(), not is_string() alone, or
        // every id gets silently filtered out here.
        $catIds = array_values(array_filter(
            $matchingCatIds,
            static fn (mixed $v): bool => is_int($v) || is_string($v)
        ));
        $catIds = array_map(static fn (mixed $v): int => (int) $v, $catIds);

        if ($catIds === []) {
            return;
        }

        $allowedCatIds = self::filterAccessibleCategoryIds(
            $catIds,
            $this->currentUser->get()
                ->forbiddenCategories
        );
        if ($allowedCatIds === []) {
            return;
        }

        // CategoryRepository::findFullCategoriesByIds() (batch 4b) returns
        // typed Category projections (P17-23 Stage 1b) -- unboxed to array
        // here since nameCompare()'s signature (shared with every other
        // name-sort call site in this project) takes array<string, mixed>.
        $cats = array_map(
            static fn (\Piwigo\Category\Projection\Category $cat): array => $cat->toArray(),
            $this->categoryRepo->findFullCategoriesByIds($allowedCatIds)
        );
        usort($cats, $this->htmlRenderer->nameCompare(...));

        $albumsFound = [];
        foreach ($cats as $cat) {
            $uppercats = $cat['uppercats'];

            $singleLink = false;
            $albumsFound[] = $this->htmlRenderer->getCatDisplayNameCache(
                $uppercats,
                '',
                $singleLink
            );
        }

        if (count($albumsFound) > 0) {
            $template->assign('ALBUMS_FOUND', $albumsFound);
        }
    }

    /**
     * Pure existence-filter (same concept as P23 batch 3a): excludes any id
     * in $forbiddenCategoriesCsv from $catIds. Extracted from
     * renderAlbumsFound() specifically so this fix is directly unit-testable
     * without needing a real search-id round trip through get_search_info()
     * (which bad_request()s on an unknown id) -- same "extract the one real
     * new piece of logic as a pure static method" precedent as
     * CategoryService::filterMenuRows()/isRecentCategory() from batches
     * 3b/4b.
     *
     * @param  list<int>  $catIds
     * @return list<int>
     */
    public static function filterAccessibleCategoryIds(array $catIds, ?string $forbiddenCategoriesCsv): array
    {
        $forbiddenIds = [];
        if ($forbiddenCategoriesCsv !== null && $forbiddenCategoriesCsv !== '') {
            $forbiddenIds = array_map(intval(...), explode(',', $forbiddenCategoriesCsv));
        }

        return array_values(array_diff($catIds, $forbiddenIds));
    }

    /**
     * @param array<string, mixed> $page
     */
    private function renderTagsFound(array $page, TemplateInterface $template): void
    {
        $searchDetails = $page['search_details'] ?? null;
        $matchingTagIds = is_array($searchDetails) ? ($searchDetails['matching_tag_ids'] ?? null) : null;
        if (! is_array($matchingTagIds)) {
            return;
        }

        // shape from SearchService::getRegularSearchResults(): list<int> --
        // see renderAlbumsFound()'s own comment for why this can't be
        // is_string() alone.
        $tagIds = array_values(array_filter(
            $matchingTagIds,
            static fn (mixed $v): bool => is_int($v) || is_string($v)
        ));

        if (count($tagIds) === 0) {
            return;
        }

        $tags = $this->tagService->getAvailableTags($tagIds);
        usort($tags, $this->htmlRenderer->tagAlphaCompare(...));
        $tagsFound = [];
        foreach ($tags as $tag) {
            $url = $this->urlService->makeIndexUrl(
                [
                    'tags' => [$tag],
                ]
            );
            $tagsFound[] = sprintf('<a href="%s">%s</a>', $url, $tag['name']);
        }

        if (count($tagsFound) > 0) {
            $template->assign('TAGS_FOUND', $tagsFound);
        }
    }

    /**
     * Shared logic for the date_posted/date_created filter blocks -- same
     * shape (thresholds → per-image bucket counters → year/month/day tree),
     * differing only in which date column, threshold set, and template
     * variable names they use.
     *
     * @param array<int, string> $langMonth
     * @param array<string, string> $labelForThreshold keyed by threshold id
     *   (e.g. '24h', '7d'), in display order
     * @param array<string, mixed> $page see render()'s own docblock
     */
    private function renderDateFilter(
        array $langMonth,
        string $userId,
        string $filterName,
        string $dbField,
        array $labelForThreshold,
        string $listTemplateVar,
        string $counterTemplateVar,
        TemplateInterface $template,
        array $page
    ): void {
        $filterCondition = $this->getClauseForFilter($filterName, $page);
        $cacheKey = 'filter_' . $filterName . '_' . $userId;
        // we use the cache pool only for fetching lines filtered only by
        // permissions
        $cacheApplicable = ! str_starts_with($filterCondition->sql, 'image_id IN');
        $cached = $cacheApplicable ? $this->cacheGet($cacheKey) : null;

        if (is_array($cached)
            and is_array($cached['pre_counters'] ?? null)
            and is_array($cached['list_of_dates'] ?? null)
        ) {
            $preCounters = $cached['pre_counters'];
            $listOfDates = $cached['list_of_dates'];
        } else {
            // SQL-modernization audit: verified, no local defect --
            // $threshold is always one of a hardcoded literal key set
            // ('24h'/'7d'/etc.) from this method's own 2 real call sites,
            // never external/dynamic; intervalForThreshold() is a fixed
            // match() over that same closed set.
            $intervalExprs = [];
            foreach (array_keys($labelForThreshold) as $threshold) {
                $intervalExprs[] = 'SUBDATE(NOW(), ' . $this->intervalForThreshold($threshold) . ') AS `' . $threshold . '`';
            }
            $intervalExprsSql = implode(",\n    ", $intervalExprs);
            $query = <<<SQL
                SELECT
                    {$intervalExprsSql}
                SQL;
            $thresholds = $this->repo->queryRows($query)[0];

            $imagesTable = Tables::images();
            $imageCategoryTable = Tables::imageCategory();
            $query = <<<SQL
                SELECT
                    DISTINCT id,
                    {$dbField} as date
                FROM {$imagesTable} AS i
                    JOIN {$imageCategoryTable} AS ic ON ic.image_id = i.id
                WHERE {$filterCondition->sql}
                SQL;

            $listOfDates = [];
            $preCounters = [];

            foreach ($this->repo->queryRows($query, $filterCondition->parameters, $filterCondition->types) as $row) {
                $date = $row['date'] ?? null;
                if (! is_string($date) || $date === '') {
                    continue;
                }

                foreach ($thresholds as $threshold => $dateLimit) {
                    if ($date > $dateLimit) {
                        $preCounters[$threshold] = ($preCounters[$threshold] ?? 0) + 1;
                    }
                }

                [$dateWithoutTime] = explode(' ', $date);
                [$y, $m] = explode('-', $dateWithoutTime);

                $listOfDates[$y]['months'][$y . '-' . $m]['days'][$dateWithoutTime]['count'] =
                    ($listOfDates[$y]['months'][$y . '-' . $m]['days'][$dateWithoutTime]['count'] ?? 0) + 1;
                $listOfDates[$y]['months'][$y . '-' . $m]['count'] =
                    ($listOfDates[$y]['months'][$y . '-' . $m]['count'] ?? 0) + 1;
                $listOfDates[$y]['count'] = ($listOfDates[$y]['count'] ?? 0) + 1;
            }

            if ($cacheApplicable) {
                // for this filter, we do not store in cache the
                // $filterRows: for a big gallery it may take more than
                // 10MB. It is smarter to store in cache the result of the
                // computation, which is just around 100 bytes.
                $this->cacheSet(
                    $cacheKey,
                    [
                        'pre_counters' => $preCounters,
                        'list_of_dates' => $listOfDates,
                    ]
                );
            }
        }

        $counters = [];
        foreach (array_keys($labelForThreshold) as $threshold) {
            $counters[$threshold] = [
                'label' => $labelForThreshold[$threshold],
                'counter' => $preCounters[$threshold] ?? 0,
            ];
        }

        // $listOfDates may have come from the persistent cache above, which
        // stores it as plain mixed data — validate each nesting level
        // defensively rather than trusting its shape.
        foreach (array_keys($listOfDates) as $y) {
            $yearBucket = $listOfDates[$y] ?? null;
            if (! is_array($yearBucket)) {
                continue;
            }
            $yearBucket['label'] = Lang::t('year %d', $y);

            $monthsBucket = $yearBucket['months'] ?? null;
            if (is_array($monthsBucket)) {
                foreach (array_keys($monthsBucket) as $ym) {
                    $monthBucket = $monthsBucket[$ym] ?? null;
                    if (! is_array($monthBucket)) {
                        continue;
                    }

                    [, $m] = explode('-', (string) $ym);
                    $monthName = $langMonth[(int) $m] ?? null;
                    $monthName = is_string($monthName) ? $monthName : '';
                    $monthBucket['label'] = $monthName . ' ' . $y;

                    $daysBucket = $monthBucket['days'] ?? null;
                    if (is_array($daysBucket)) {
                        foreach (array_keys($daysBucket) as $ymd) {
                            $dayBucket = $daysBucket[$ymd] ?? null;
                            if (! is_array($dayBucket)) {
                                continue;
                            }
                            $dayBucket['label'] = \Piwigo\Core\DateHelper::formatDate($ymd);
                            $daysBucket[$ymd] = $dayBucket;
                        }
                        $monthBucket['days'] = $daysBucket;
                    }

                    $monthsBucket[$ym] = $monthBucket;
                }
                $yearBucket['months'] = $monthsBucket;
            }

            $listOfDates[$y] = $yearBucket;
        }
        krsort($listOfDates);

        $template->assign($listTemplateVar, $listOfDates);
        $template->assign($counterTemplateVar, $counters);
    }

    private function intervalForThreshold(string $threshold): string
    {
        return match ($threshold) {
            '24h' => 'INTERVAL 24 HOUR',
            '7d' => 'INTERVAL 7 DAY',
            '30d' => 'INTERVAL 30 DAY',
            '3m' => 'INTERVAL 3 MONTH',
            '6m' => 'INTERVAL 6 MONTH',
            '12m' => 'INTERVAL 12 MONTH',
            default => 'INTERVAL 0 DAY',
        };
    }

    /**
     * Returns the SQL WHERE clause to be used to build filter values.
     *
     * @since 15
     *
     * @param array<string, mixed> $page see render()'s own docblock --
     *   by-ref because getItemsForFilter() caches its computed
     *   intersection back onto $page['search_details'] for later calls
     *   within the same render() invocation to reuse (see that method's
     *   own comment); losing that write-back across calls would still be
     *   correct, just slower (a fresh array_intersect() per filter
     *   instead of a cache hit).
     *
     * SQL-modernization audit: was string ('1=1' . a raw permission
     * fragment, or 'image_id IN (id,id,...)' built via implode() CSV
     * splicing) -- now SqlCondition throughout. Every caller below
     * checks `str_starts_with($condition->sql, 'image_id IN')` where the
     * old code checked `preg_match('/^image_id IN/', $filterClause)`.
     */
    private function getClauseForFilter(string $filterName, array &$page): SqlCondition
    {
        $otherFiltersItems = $this->getItemsForFilter($filterName, $page);
        if ($otherFiltersItems === false) {
            // $page['search_details'] is set (as
            // SearchService::getRegularSearchResults()'s return
            // ['search_details']) in Section\SectionPopulator; 'forbidden' is
            // itself set as a SqlCondition a few lines above in this same
            // render().
            $searchDetails = is_array($page['search_details'] ?? null) ? $page['search_details'] : [];
            $forbidden = $searchDetails['forbidden'] ?? null;
            $forbiddenCondition = $forbidden instanceof SqlCondition ? $forbidden : new SqlCondition('');

            return SqlCondition::combine('AND', new SqlCondition('1=1'), $forbiddenCondition);
        }

        // getItemsForFilter() ultimately pulls its values from
        // $page['search_details']['image_ids_for_filter'], which is declared
        // array<string, mixed> (getRegularSearchResults()'s return shape) — in
        // practice always image ids, narrowed to int here for binding.
        $otherFiltersItemInts = array_map(
            static fn (int|string $v): int => (int) $v,
            $otherFiltersItems
        );

        return new SqlCondition(
            'image_id IN (:otherFiltersItems)',
            [
                'otherFiltersItems' => $otherFiltersItemInts,
            ],
            [
                'otherFiltersItems' => ArrayParameterType::INTEGER,
            ],
        );
    }

    /**
     * Returns the list of items (image_ids) to be used to build filter
     * values for a given filter. Depends on the other filters. Use a cache
     * to avoid computing the same large array_intersect several times.
     *
     * @since 15
     *
     * @param array<string, mixed> $page see getClauseForFilter()'s own
     *   docblock for why this is by-ref
     * @return list<int|string>|false array of image_ids (or the literal
     *   -1 sentinel meaning "none"), or false
     */
    private function getItemsForFilter(string $filterName, array &$page): false|array
    {
        $logger = $this->currentLogger->get();

        // $page['search_details'] is set (as
        // SearchService::getRegularSearchResults()'s return
        // ['search_details']) in Section\SectionPopulator.
        $searchDetails = is_array($page['search_details'] ?? null) ? $page['search_details'] : [];
        $imageIdsForFilter = is_array($searchDetails['image_ids_for_filter'] ?? null) ? $searchDetails['image_ids_for_filter'] : [];

        $otherFilters = array_diff(array_keys($imageIdsForFilter), [$filterName]);

        if ($otherFilters === []) {
            return false;
        }

        $cacheKey = md5(implode(',', $otherFilters));

        $filterCache = is_array($searchDetails[__METHOD__] ?? null) ? $searchDetails[__METHOD__] : [];

        if (! isset($filterCache[$cacheKey])) {
            $functionStart = \Piwigo\Core\TimingHelper::getMoment();

            // every entry of $imageIdsForFilter is either a SearchRepository
            // id list (list<string|null>) or, for 'expert', the already-narrowed
            // result of SearchService::getQuickSearchResults() — normalize
            // each to a plain string-id list here so array_intersect() below
            // has an unambiguous element type (same normalization as
            // SearchService::getRegularSearchResults()).
            $firstFilterKey = array_shift($otherFilters);
            $firstFilterRaw = $imageIdsForFilter[$firstFilterKey] ?? null;
            $otherFiltersItems = [];
            if (is_array($firstFilterRaw)) {
                foreach ($firstFilterRaw as $id) {
                    if (is_scalar($id)) {
                        $otherFiltersItems[] = (string) $id;
                    }
                }
            }

            foreach ($otherFilters as $otherFilter) {
                $nextFilterRaw = $imageIdsForFilter[$otherFilter] ?? null;
                $nextFilterItems = [];
                if (is_array($nextFilterRaw)) {
                    foreach ($nextFilterRaw as $id) {
                        if (is_scalar($id)) {
                            $nextFilterItems[] = (string) $id;
                        }
                    }
                }
                $otherFiltersItems = array_intersect($otherFiltersItems, $nextFilterItems);
            }

            $otherFiltersItems = array_values(array_unique($otherFiltersItems));

            $debugMsg = '[' . __METHOD__ . '] cache computed for ' . (count($otherFilters) + 1) . ' other filters';
            $debugMsg .= ' (' . count($otherFiltersItems) . ' items)';
            $debugMsg .= ', time = ' . \Piwigo\Core\TimingHelper::getElapsedTime($functionStart, \Piwigo\Core\TimingHelper::getMoment());
            $logger->debug($debugMsg);

            if ($otherFiltersItems === []) {
                $otherFiltersItems = [-1];
            }

            // write the whole 'search_details' structure back at once (rather
            // than chaining offset-writes through $page directly) so every
            // intermediate container is a value we've already proven is an array.
            $filterCache[$cacheKey] = $otherFiltersItems;
            $searchDetails[__METHOD__] = $filterCache;
            $page['search_details'] = $searchDetails;

            return $otherFiltersItems;
        }

        $cachedItems = $filterCache[$cacheKey];
        if (! is_array($cachedItems)) {
            return [];
        }

        // only ever populated a few lines above (in this same method) with an
        // array<int, mixed> $otherFiltersItems.
        /** @var list<int|string> $cachedItems */
        return $cachedItems;
    }
}
