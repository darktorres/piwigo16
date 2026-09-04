<?php

declare(strict_types=1);

namespace Piwigo\Search;

use DateTime;
use Doctrine\DBAL\ArrayParameterType;
use Latte\Runtime\Html;
use Piwigo\Auth\AccessControl;
use Piwigo\Cache\SearchResultsCachePool;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\Projection\Category;
use Piwigo\Common\Enum\Section;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\FilterViewDefinition;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\DateHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\TimingHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\NoMatchSentinel;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
use Piwigo\Search\Projection\AddedByFilterCount;
use Piwigo\Search\Projection\AuthorFilterCount;
use Piwigo\Search\Projection\AuthorRule;
use Piwigo\Search\Projection\CategoryRule;
use Piwigo\Search\Projection\DateFilterCounter;
use Piwigo\Search\Projection\DateFilterDay;
use Piwigo\Search\Projection\DateFilterMonth;
use Piwigo\Search\Projection\DateFilterOptions;
use Piwigo\Search\Projection\DateFilterYear;
use Piwigo\Search\Projection\RangeBounds;
use Piwigo\Search\Projection\RangeFilterOptions;
use Piwigo\Search\Projection\SearchFilterData;
use Piwigo\Search\Projection\SearchFilterResult;
use Piwigo\Search\Projection\SearchRules;
use Piwigo\Search\Projection\TagsRule;
use Piwigo\Section\SectionContext;
use Piwigo\Tag\TagService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;

/**
 * Renders the search-refinement sidebar (author/tags/date_posted/
 * date_created/added_by/album/filetypes/ratings/filesize/ratios/height/
 * width filters) plus the "ALBUMS_FOUND"/"TAGS_FOUND" search-hint block.
 *
 * The per-filter row/count caches below go through
 * {@see \Piwigo\Cache\SearchResultsCachePool} (30s TTL).
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
        private Lang $lang,
        private AccessControl $accessControl,
        private HtmlRenderingInterface $htmlRenderer,
        private SearchRepository $repo,
        private SearchService $searchService,
        private TagService $tagService,
        private CategoryRepository $categoryRepo,
        private PermissionService $permissionService,
        private UrlServiceInterface $urlService,
        private CurrentLogger $currentLogger,
        private CurrentUser $currentUser,
        private CurrentConfig $currentConfig,
        private UserService $userService,
        private SearchResultsCachePool $searchResultsCachePool,
    ) {}

    private function cacheGet(string $key): mixed
    {
        $item = $this->searchResultsCachePool->getItem($key);

        return $item->isHit() ? $item->get() : null;
    }

    private function cacheSet(string $key, mixed $value): void
    {
        $pool = $this->searchResultsCachePool;
        $item = $pool->getItem($key);
        $item->set($value);
        $pool->save($item);
    }

    /**
     * $page is a plain local array shaped like the old global $page --
     * built once here from $sectionContext's own fields, mutated in place
     * ('search_details') by this method and every private helper it
     * calls, and not visible outside this call.
     *
     * Returns a {@see SearchFilterResult}: `$resolvedSearchId` alone (null
     * when this page isn't a search results page) is what the one real
     * caller (GalleryController) passes straight into its own
     * HistoryService::logVisit() call; `$data` is the full filter-sidebar
     * payload GalleryController threads into its own {@see
     * \Piwigo\Controller\Projection\SearchFiltersView} construction --
     * `Piwigo\Search\*` is L2bExtendedDomain and may not depend on
     * `Renderer`/`View` (L3Presentation) directly, same split as
     * `Piwigo\Category\CategoryDefaultRenderer`/`CategoryCatsRenderer`.
     */
    public function render(SectionContext $sectionContext): SearchFilterResult
    {
        $page = [
            'section' => $sectionContext->section,
            'search' => $sectionContext->search,
            'search_details' => $sectionContext->searchDetails,
            'items' => $sectionContext->items,
            'start' => $sectionContext->start,
            'chronology_field' => $sectionContext->chronologyField,
        ];

        $tagService = $this->tagService;

        $filtersViewsRaw = $this->currentConfig->filtersViews->filters ?? $this->currentConfig->defaultFiltersViews;

        // filtersViews->filters (unlike the old raw filters_views config
        // value) never commingles the sibling 'last_filters_conf' flag with
        // the per-filter entries -- FilterViewsSelection keeps it as its
        // own field -- so every entry here is already a real per-filter
        // definition; no filtering needed, just the VO -> array unwrap this
        // method's own boolean-access rewrite below needs.
        // Kept as FilterViewDefinition objects. The former toArray() unwrap
        // existed only to let the access-resolution loop below overwrite
        // 'access' in place; that loop builds its own bool map now, so the
        // flatten has no remaining purpose and its `array<string, mixed>`
        // was what typed all 42 template reads of `access` as mixed
        // (P58-B3).
        $filtersViews = $filtersViewsRaw;

        // we add the search_details emptiness check in this condition
        // because it only applies to regular search, not the legacy
        // qsearch (SectionContext::searchDetails stays [] whenever
        // SectionPopulator's own search branch took the qsearch path
        // instead -- see that class's search-section handling). As
        // Piwigo 14 will still be able to show an old quicksearch
        // result, we must check this condition too.
        if ($page['section'] !== Section::Search || $page['search_details'] === []) {
            return new SearchFilterResult(resolvedSearchId: null, data: null);
        }

        // A separate name -> bool map, not a copy of $filtersViews with its
        // own 'access' overwritten. The old shape existed only because an
        // array lets you swap a string field for a bool in place, which is
        // exactly what forced the flatten above and left every template
        // read of `access` typed mixed (P58-B3). This map answers "may the
        // current user see this filter"; $filtersViews keeps answering
        // "who is it configured for", and the two no longer share a slot.
        $displayFilters = [];
        foreach ($filtersViewsRaw as $filtName => $filtConf) {
            $displayFilters[$filtName] = $filtConf->access === 'everybody'
                || ($filtConf->access === 'admins-only' && $this->accessControl->isAdmin())
                || ($filtConf->access === 'registered-users' && $this->accessControl->isClassicUser());
        }

        $userId = (string) $this->currentUser->get()
            ->id->value;

        $tags = null;
        $authors = null;
        $addedBy = null;
        $fullname_of_json = null;
        $filetypes = null;
        $rating = null;
        $filesize = null;
        $ratios = null;
        $height = null;
        $width = null;
        $datePostedFilter = null;
        $dateCreatedFilter = null;

        $langMonth = $this->lang->months();

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
        $rawSearchFields = $mySearch['fields'] ?? null;
        $rules = SearchRules::fromArray(is_array($rawSearchFields) ? array_filter($rawSearchFields, is_string(...), ARRAY_FILTER_USE_KEY) : []);

        // 'forbidden' is a SqlCondition -- getClauseForFilter() below
        // combines it via SqlCondition::combine() instead of string
        // concatenation, so no leading " AND " prefix is needed. This key
        // is never read outside this file.
        //
        // Every real consumer of this condition throughout this file
        // (author/filetypes/added_by/ratings/filesize/ratios/height/width
        // filter queries) runs as DQL against `ImageEntity i INNER JOIN
        // ImageCategoryEntity ic` -- reuses SearchService::
        // forbiddenCondition() (that class's own docblock has the full
        // `PermissionCriteria` rationale) instead of rebuilding the same
        // 4-call combination here.
        $page['search_details']['forbidden'] = $this->searchService->forbiddenCondition();

        if ($rules->allwords !== null and ! ($displayFilters['words'])) {
            $rules->allwords = null;
        }

        if ($rules->tags instanceof TagsRule and $displayFilters['tags']) {
            $filterTags = [];

            // Known limitation: TagService::getAvailableTags() below isn't
            // cached/reused across this request even though it may already
            // have run once for other purposes (e.g. building the menu) --
            // a real but non-blocking optimization opportunity on
            // large-gallery installs, not a defect.
            $tagWords = $rules->tags->words;

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

            $tags = $filterTags;

            $filterTagIds = count($filterTags) > 0 ? $extractTagIds($filterTags) : [];

            // in case the search has forbidden tags for current user, we
            // need to filter the search rule
            $rules->tags->words = array_values(array_intersect($tagWords, $filterTagIds));
        } elseif ($rules->tags instanceof TagsRule) {
            $rules->tags = null;
        }

        if ($rules->expert !== null) {
            if (! $displayFilters['expert']) {
                $rules->expert = null;
            } else {
                $this->lang->load('help_quick_search.lang');
            }
        }

        if ($rules->author instanceof AuthorRule and $displayFilters['author']) {
            $filterClause = $this->getClauseForFilter('author', $page);
            $filterCondition = $filterClause->condition;
            $groupCondition = SqlCondition::combine('AND', $filterCondition, SqlCondition::fromRawSql('i.author IS NOT NULL'));

            if ($filterClause->cacheApplicable) {
                // we use the cache pool only for fetching lines filtered
                // only by permissions
                $cacheKey = 'author_rows_' . $userId;
                $filterRows = $this->cacheGet($cacheKey);
                if (! is_array($filterRows)) {
                    $filterRows = $this->repo->countImagesGroupedBy('i.author', 'author', $groupCondition);
                    $this->cacheSet($cacheKey, $filterRows);
                }
            } else {
                $filterRows = $this->repo->countImagesGroupedBy('i.author', 'author', $groupCondition);
            }

            // the cache pool stores this row set as plain mixed data, so
            // validate each row's shape defensively rather than trusting it.
            $authorNames = [];
            $authors = [];
            foreach ($filterRows as $authorRow) {
                if (! is_array($authorRow)) {
                    continue;
                }

                $authorName = $authorRow['author'] ?? null;
                $authors[] = new AuthorFilterCount(
                    author: is_string($authorName) ? $authorName : '',
                    counter: self::filterCount($authorRow['counter'] ?? null),
                );

                if (is_string($authorName)) {
                    $authorNames[] = $authorName;
                }
            }
            $authorWords = $rules->author->words;

            // in case the search has forbidden authors for current user, we
            // need to filter the search rule
            $rules->author->words = array_values(array_intersect($authorWords, $authorNames));
        } elseif ($rules->author instanceof AuthorRule) {
            $rules->author = null;
        }

        if ($rules->datePosted !== null and $displayFilters['post_date']) {
            $dateFilterResult = $this->renderDateFilter(
                $langMonth,
                $userId,
                'date_posted',
                'i.dateAvailable',
                [
                    '24h' => $this->lang->t('last 24 hours'),
                    '7d' => $this->lang->t('last 7 days'),
                    '30d' => $this->lang->t('last 30 days'),
                    '3m' => $this->lang->t('last 3 months'),
                    '6m' => $this->lang->t('last 6 months'),
                ],
                $page
            );
            $datePostedFilter = $dateFilterResult;
        } elseif ($rules->datePosted !== null) {
            $rules->datePosted = null;
        }

        if ($rules->dateCreated !== null and $displayFilters['creation_date']) {
            $dateFilterResult = $this->renderDateFilter(
                $langMonth,
                $userId,
                'date_created',
                'i.dateCreation',
                [
                    '7d' => $this->lang->t('last 7 days'),
                    '30d' => $this->lang->t('last 30 days'),
                    '3m' => $this->lang->t('last 3 months'),
                    '6m' => $this->lang->t('last 6 months'),
                    '12m' => $this->lang->t('last 12 months'),
                ],
                $page
            );
            $dateCreatedFilter = $dateFilterResult;
        } elseif ($rules->dateCreated !== null) {
            $rules->dateCreated = null;
        }

        if ($rules->addedBy !== null and $displayFilters['added_by']) {
            $filterClause = $this->getClauseForFilter('added_by', $page);
            $filterCondition = $filterClause->condition;

            if ($filterClause->cacheApplicable) {
                // we use the cache pool only for fetching lines filtered
                // only by permissions
                $cacheKey = 'added_by_rows_' . $userId;
                $filterRows = $this->cacheGet($cacheKey);
                if (! is_array($filterRows)) {
                    $filterRows = $this->repo->countImagesGroupedBy('IDENTITY(i.addedByUser)', 'added_by_id', $filterCondition, true);
                    $this->cacheSet($cacheKey, $filterRows);
                }
            } else {
                $filterRows = $this->repo->countImagesGroupedBy('IDENTITY(i.addedByUser)', 'added_by_id', $filterCondition, true);
            }

            // the cache pool stores this row set as plain mixed data, so
            // validate each row's shape defensively rather than trusting it.
            $addedByRows = [];
            foreach ($filterRows as $addedByRow) {
                if (is_array($addedByRow)) {
                    $addedByRows[] = $addedByRow;
                }
            }

            $userIds = [];
            $addedBy = [];

            if ($addedByRows !== []) {
                // now let's find the usernames of added_by users. added_by_id
                // is a native ?int here (DQL-hydrated) -- is_int()||is_string(),
                // not is_string() alone, or every id gets silently filtered
                // out; UserService::getUsernamesByIds()/the $usernameOf
                // lookup below both accept either (PHP coerces a numeric
                // array key either way).
                foreach ($addedByRows as $row) {
                    $rowAddedById = $row['added_by_id'] ?? null;
                    if (is_int($rowAddedById) || is_string($rowAddedById)) {
                        $userIds[] = (string) $rowAddedById;
                    }
                }

                // Reuses UserService::getUsernamesByIds() -- the same
                // id => username map shape this block already builds.
                $usernameOf = $this->userService->getUsernamesByIds($userIds);

                foreach ($addedByRows as $row) {
                    $addedById = $row['added_by_id'] ?? null;
                    $resolvable = is_int($addedById) || is_string($addedById);
                    // getUsernamesByIds() returns a string map, so the
                    // fallback label is the only other possibility here.
                    $addedByName = $resolvable
                        ? ($usernameOf[$addedById] ?? 'user #' . $addedById . ' (deleted)')
                        : '';

                    $addedBy[] = new AddedByFilterCount(
                        addedById: $resolvable ? $addedById : null,
                        addedByName: $addedByName,
                        counter: self::filterCount($row['counter'] ?? null),
                    );
                }
            }

            $addedByIds = $rules->addedBy;

            // in case the search has forbidden added_by users for current
            // user, we need to filter the search rule
            $rules->addedBy = array_values(array_intersect($addedByIds, $userIds));
        } elseif ($rules->addedBy !== null) {
            $rules->addedBy = null;
        }

        if ($rules->cat instanceof CategoryRule and $displayFilters['album']) {
            $catWords = $rules->cat->words;

            if (count($catWords) > 0) {
                $fullnameOf = [];

                // Uses the same live PermissionService condition every
                // other visibility check in this class uses.
                //
                // `categories` is a single-table DQL query, no image join
                // -- the category ids are bound as a parameter, not
                // spliced into the SQL.
                $permissionCondition = $this->permissionService->getPermissionCriteria()
                    ->visibleCategoriesCondition('c.id');
                $catWordsInts = array_map(static fn (int|string $v): int => (int) $v, $catWords);
                $idsCondition = SqlCondition::fromRawSql(
                    'c.id IN (:catWords)',
                    [
                        'catWords' => $catWordsInts,
                    ],
                    [
                        'catWords' => ArrayParameterType::INTEGER,
                    ],
                );
                $combinedCondition = SqlCondition::combine('AND', $idsCondition, $permissionCondition);

                foreach ($this->repo->findCategoryIdsAndUppercats($combinedCondition) as $row) {
                    // The $url argument here has no observable effect: it
                    // only controls the href of the <a> tag
                    // getCatDisplayNameCache() wraps each name in, and that
                    // wrapper gets stripped by strip_tags() below either
                    // way (this array is JSON-encoded for a JS-consumed
                    // autocomplete label, not rendered as a link).
                    $catDisplayName = $this->htmlRenderer->getCatDisplayNameCache(
                        $row->uppercats,
                        'admin.php?page=album-'
                    );

                    $fullnameOf[$row->id->value] = strip_tags($catDisplayName);
                }

                $fullname_of_json = json_encode($fullnameOf);

                // in case the search has forbidden albums for current user,
                // we need to filter the search rule
                $rules->cat->words = array_values(array_intersect($catWords, array_keys($fullnameOf)));
            }
        } elseif ($rules->cat instanceof CategoryRule) {
            $rules->cat = null;
        }

        if ($rules->filetypes !== null and $displayFilters['file_type']) {
            $filterClause = $this->getClauseForFilter('filetypes', $page);
            $filterCondition = $filterClause->condition;

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
            $searchDetailsForbidden = $searchDetailsForbiddenRaw instanceof SqlCondition ? $searchDetailsForbiddenRaw : SqlCondition::fromRawSql('');
            $allExtsCondition = $searchDetailsForbidden;

            // SUBSTRING_INDEX has no DQL/portable-SQL built-in equivalent
            // -- uses the custom SubstringIndexFunction
            // (src/Piwigo/Db/DqlFunction/), same MySQL-verified/others-
            // best-effort convention as every other function in that
            // directory. extToCounterMap() builds the 'ext' => 'counter'
            // shape from countImagesGroupedBy()'s row list.
            $extToCounterMap = static function (array $rows): array {
                $map = [];
                foreach ($rows as $row) {
                    if (is_array($row) && is_string($row['ext'] ?? null)) {
                        $map[$row['ext']] = is_numeric($row['counter'] ?? null) ? (int) $row['counter'] : 0;
                    }
                }

                return $map;
            };

            $cachedExts = $this->cacheGet($cacheKey);
            if (is_array($cachedExts)) {
                $allExts = self::toCounterMap($cachedExts);
            } else {
                $allExts = $extToCounterMap($this->repo->countImagesGroupedBy("SUBSTRING_INDEX(i.path, '.', -1)", 'ext', $allExtsCondition, true));
                $this->cacheSet($cacheKey, $allExts);
            }

            if (! $filterClause->cacheApplicable) {
                $filteredExts = $extToCounterMap($this->repo->countImagesGroupedBy("SUBSTRING_INDEX(i.path, '.', -1)", 'ext', $filterCondition, true));

                $exts = [];
                foreach ($allExts as $ext => $counter) {
                    $exts[$ext] = $filteredExts[$ext] ?? 0;
                }

                $filetypes = $exts;
            } else {
                $filetypes = $allExts;
            }
        } elseif ($rules->filetypes !== null) {
            $rules->filetypes = null;
        }

        // For rating
        if ($this->currentConfig->rateEnabled) {
            $show_filter_ratings = true;

            if ($rules->ratings !== null and $displayFilters['rating']) {
                $filterClause = $this->getClauseForFilter('ratings', $page);
                $filterCondition = $filterClause->condition;

                $cacheKey = 'ratings_' . $userId;
                $cacheApplicable = $filterClause->cacheApplicable;
                $cachedRatings = $cacheApplicable ? $this->cacheGet($cacheKey) : null;
                // Rebuilt bucket by bucket rather than taken as-is: the
                // rating filter is a fixed 0..5 set (array_fill(0, 6, 0)
                // below), and saying so gives the template array<int, int>
                // instead of array<array-key, int>, which is what its own
                // `0 === $k` needs. It also means a cache entry that lost or
                // gained a bucket cannot change how many controls render.
                $ratings = null;
                if (is_array($cachedRatings)) {
                    $cachedCounts = self::toCounterMap($cachedRatings);
                    $ratings = [];
                    foreach (range(0, 5) as $bucket) {
                        $ratings[$bucket] = $cachedCounts[$bucket] ?? 0;
                    }
                }

                if ($ratings === null) {
                    $filterRows = $this->repo->findDistinctImageRows(['i.ratingScore AS rating_score'], $filterCondition);

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
                $rating = $ratings;
            } elseif ($rules->ratings !== null) {
                $rules->ratings = null;
            }
        } else {
            $show_filter_ratings = false;
            $rules->ratings = null;
        }

        // For filesize
        if ($rules->filesizeMin !== null && $rules->filesizeMax !== null and $displayFilters['file_size']) {
            $filterClause = $this->getClauseForFilter('filesize', $page);
            $filterCondition = $filterClause->condition;

            $filesizes = [];

            foreach ($this->repo->findDistinctImageRows(['i.filesize AS filesize'], $filterCondition) as $row) {
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

            $filesizeMin = $rules->filesizeMin;
            $filesizeMax = $rules->filesizeMax;

            // warning: we will (hopefully) have smarter values for filters.
            // The min/max of the current search won't always be the
            // first/last values found. It's going to be a problem with
            // this way to select selected values
            $filesize = new RangeFilterOptions(
                list: implode(',', $uniqueFilesizes),
                selected: new RangeBounds(
                    min: (is_numeric($filesizeMin) && (float) $filesizeMin !== 0.0) ? sprintf('%.1f', (float) $filesizeMin / 1024.0) : RangeBounds::value($uniqueFilesizes[0]),
                    max: (is_numeric($filesizeMax) && (float) $filesizeMax !== 0.0) ? sprintf('%.1f', (float) $filesizeMax / 1024.0) : RangeBounds::value(end($uniqueFilesizes)),
                ),
            );

        } elseif ($rules->filesizeMin !== null && $rules->filesizeMax !== null and ! ($displayFilters['file_size'])) {
            $rules->filesizeMin = null;
            $rules->filesizeMax = null;
        }

        if ($rules->ratios !== null and $displayFilters['ratio']) {
            $filterClause = $this->getClauseForFilter('ratios', $page);
            $filterCondition = $filterClause->condition;

            $cacheKey = 'ratios_' . $userId;
            $cacheApplicable = $filterClause->cacheApplicable;
            $cachedRatios = $cacheApplicable ? $this->cacheGet($cacheKey) : null;
            $ratios = is_array($cachedRatios) ? self::toCounterMap($cachedRatios) : null;

            if ($ratios === null) {
                $notNullCondition = SqlCondition::combine('AND', $filterCondition, SqlCondition::fromRawSql('i.width IS NOT NULL AND i.height IS NOT NULL'));
                $filterRows = $this->repo->findDistinctImageRows(['i.width AS width', 'i.height AS height'], $notNullCondition);

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
        } elseif ($rules->ratios !== null) {
            $rules->ratios = null;
        }

        if ($rules->heightMin !== null and $rules->heightMax !== null and $displayFilters['height']) {
            $filterClause = $this->getClauseForFilter('height', $page);
            $filterCondition = $filterClause->condition;

            $notNullCondition = SqlCondition::combine('AND', $filterCondition, SqlCondition::fromRawSql('i.height IS NOT NULL'));

            if ($filterClause->cacheApplicable) {
                // we use the cache pool only for fetching lines filtered
                // only by permissions
                $cacheKey = 'height_rows_' . $userId;
                $filterRows = $this->cacheGet($cacheKey);
                if (! is_array($filterRows)) {
                    $filterRows = $this->repo->findDistinctImageColumnValues('i.height', $notNullCondition);
                    $this->cacheSet($cacheKey, $filterRows);
                }
            } else {
                $filterRows = $this->repo->findDistinctImageColumnValues('i.height', $notNullCondition);
            }

            // the cache pool stores this row set as plain mixed data, so
            // validate each value defensively rather than trusting it.
            $heights = [];
            foreach ($filterRows as $heightValue) {
                if (is_string($heightValue)) {
                    $heights[] = $heightValue;
                }
            }

            $heightMin = $rules->heightMin;
            $heightMax = $rules->heightMax;

            $height = new RangeFilterOptions(
                list: implode(',', $heights),
                selected: new RangeBounds(
                    min: RangeBounds::value(($heightMin !== '' && $heightMin !== 0) ? $heightMin : ($heights[0] ?? null)),
                    max: RangeBounds::value(($heightMax !== '' && $heightMax !== 0) ? $heightMax : end($heights)),
                ),
            );

        } elseif ($rules->heightMin !== null && $rules->heightMax !== null and ! ($displayFilters['height'])) {
            $rules->heightMin = null;
            $rules->heightMax = null;
        }

        if ($rules->widthMin !== null and $rules->widthMax !== null and $displayFilters['width']) {
            $filterClause = $this->getClauseForFilter('width', $page);
            $filterCondition = $filterClause->condition;

            $notNullCondition = SqlCondition::combine('AND', $filterCondition, SqlCondition::fromRawSql('i.width IS NOT NULL'));

            if ($filterClause->cacheApplicable) {
                // we use the cache pool only for fetching lines filtered
                // only by permissions
                $cacheKey = 'width_rows_' . $userId;
                $filterRows = $this->cacheGet($cacheKey);
                if (! is_array($filterRows)) {
                    $filterRows = $this->repo->findDistinctImageColumnValues('i.width', $notNullCondition);
                    $this->cacheSet($cacheKey, $filterRows);
                }
            } else {
                $filterRows = $this->repo->findDistinctImageColumnValues('i.width', $notNullCondition);
            }

            // the cache pool stores this row set as plain mixed data, so
            // validate each value defensively rather than trusting it.
            $widths = [];
            foreach ($filterRows as $widthValue) {
                if (is_string($widthValue)) {
                    $widths[] = $widthValue;
                }
            }

            $widthMin = $rules->widthMin;
            $widthMax = $rules->widthMax;

            $width = new RangeFilterOptions(
                list: implode(',', $widths),
                selected: new RangeBounds(
                    min: RangeBounds::value(($widthMin !== '' && $widthMin !== 0) ? $widthMin : ($widths[0] ?? null)),
                    max: RangeBounds::value(($widthMax !== '' && $widthMax !== 0) ? $widthMax : end($widths)),
                ),
            );

        } elseif ($rules->widthMin !== null && $rules->widthMax !== null and ! ($displayFilters['width'])) {
            $rules->widthMin = null;
            $rules->widthMax = null;
        }

        $search_id = $page['search'] ?? null;
        $search_id = is_string($search_id) ? $search_id : null;

        $albumsFound = null;
        $tagsFound = null;

        // $page['search_details'] is already known array here (guarded above).
        $pageStart = $page['start'] ?? null;
        if ((is_numeric($pageStart) ? (int) $pageStart : 0) === 0 and ! isset($page['chronology_field'])) {
            $albumsFound = $this->renderAlbumsFound($page, $userId);
            $tagsFound = $this->renderTagsFound($page);
        }

        $mySearch['fields'] = $rules->toArray();

        return new SearchFilterResult(
            resolvedSearchId: $resolvedSearchId,
            data: new SearchFilterData(
                displayFilter: $filtersViews,
                showFilterRatings: $show_filter_ratings,
                gp: json_encode($mySearch),
                searchId: $search_id,
                tags: $tags,
                authors: $authors,
                addedBy: $addedBy,
                fullnameOf: $fullname_of_json,
                filetypes: $filetypes,
                rating: $rating,
                filesize: $filesize,
                ratios: $ratios,
                height: $height,
                width: $width,
                albumsFound: $albumsFound,
                tagsFound: $tagsFound,
                datePostedFilter: $datePostedFilter,
                dateCreatedFilter: $dateCreatedFilter,
            ),
        );
    }

    /**
     * The "ALBUMS_FOUND" search-hint block: categories whose name/comment
     * matched the search text (SearchService::searchAllwords()'s
     * $matching_cat_ids). searchAllwords()'s category-name/comment match
     * applies no forbidden-categories condition of its own -- $cat_ids
     * can genuinely contain a category this user can't see, so this
     * filters against CurrentUser::get()->forbiddenCategories before
     * loading row data via CategoryRepository::findFullCategoriesByIds().
     * This permission filter is real and load-bearing, not redundant.
     *
     * @param array<string, mixed> $page
     * @return list<Html>|null
     */
    private function renderAlbumsFound(array $page, string $userId): ?array
    {
        $searchDetails = $page['search_details'] ?? null;
        $matchingCatIds = is_array($searchDetails) ? ($searchDetails['matching_cat_ids'] ?? null) : null;
        if (! is_array($matchingCatIds)) {
            return null;
        }

        // shape from SearchService::getRegularSearchResults(): list<int>
        // (ids come back as native int under this project's mysqli driver
        // config) -- is_int()||is_string(), not is_string() alone, or
        // every id gets silently filtered out here.
        $catIds = array_values(array_filter(
            $matchingCatIds,
            static fn (mixed $v): bool => is_int($v) || is_string($v)
        ));
        $catIds = array_map(static fn (mixed $v): int => (int) $v, $catIds);

        if ($catIds === []) {
            return null;
        }

        $allowedCatIds = self::filterAccessibleCategoryIds(
            $catIds,
            $this->currentUser->get()
                ->forbiddenCategories
        );
        if ($allowedCatIds === []) {
            return null;
        }

        // CategoryRepository::findFullCategoriesByIds() returns typed
        // Category projections -- unboxed to array here since
        // nameCompare()'s signature (shared with every other name-sort
        // call site in this project) takes array<string, mixed>.
        $allowedCatIdVos = array_filter(array_map(CategoryId::tryFrom(...), $allowedCatIds), static fn (mixed $id): bool => $id instanceof CategoryId);
        $cats = array_map(
            static fn (Category $cat): array => $cat->toArray(),
            $this->categoryRepo->findFullCategoriesByIds($allowedCatIdVos)
        );
        usort($cats, $this->htmlRenderer->nameCompare(...));

        $albumsFound = [];
        foreach ($cats as $cat) {
            $uppercats = $cat['uppercats'];

            $singleLink = false;
            $albumsFound[] = new Html($this->htmlRenderer->getCatDisplayNameCache(
                $uppercats,
                '',
                $singleLink
            ));
        }

        return count($albumsFound) > 0 ? $albumsFound : null;
    }

    /**
     * Pure existence-filter: excludes any id in $forbiddenCategoriesCsv
     * from $catIds. Extracted as a static method so it's directly
     * unit-testable without needing a real search-id round trip through
     * get_search_info() (which bad_request()s on an unknown id).
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
     * @return list<Html>|null
     */
    private function renderTagsFound(array $page): ?array
    {
        $searchDetails = $page['search_details'] ?? null;
        $matchingTagIds = is_array($searchDetails) ? ($searchDetails['matching_tag_ids'] ?? null) : null;
        if (! is_array($matchingTagIds)) {
            return null;
        }

        // shape from SearchService::getRegularSearchResults(): list<int> --
        // see renderAlbumsFound()'s own comment for why this can't be
        // is_string() alone.
        $tagIds = array_values(array_filter(
            $matchingTagIds,
            static fn (mixed $v): bool => is_int($v) || is_string($v)
        ));

        if (count($tagIds) === 0) {
            return null;
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
            $tagsFound[] = new Html(sprintf('<a href="%s">%s</a>', $url, htmlspecialchars($tag['name'])));
        }

        return count($tagsFound) > 0 ? $tagsFound : null;
    }

    /**
     * Shared logic for the date_posted/date_created filter blocks -- same
     * shape (thresholds → per-image bucket counters → year/month/day tree),
     * differing only in which date column and threshold set they use.
     * Returns the pair instead of assigning it directly (unlike the
     * original, single-caller-shaped `$listTemplateVar`/`$counterTemplateVar`
     * dynamic keys) -- render() itself picks which of its own
     * listDatePosted/datePosted vs. listDateCreated/dateCreated pair of
     * {@see SearchFilterData} fields each of this method's 2 real call
     * sites feeds.
     *
     * @param array<int, string> $langMonth
     * @param string $dqlField DQL property path (`i.dateAvailable`/
     *   `i.dateCreation`)
     * @param array<string, string> $labelForThreshold keyed by threshold id
     *   (e.g. '24h', '7d'), in display order
     * @param array<string, mixed> $page see render()'s own docblock
     */
    private function renderDateFilter(
        array $langMonth,
        string $userId,
        string $filterName,
        string $dqlField,
        array $labelForThreshold,
        array $page
    ): DateFilterOptions {
        $filterClause = $this->getClauseForFilter($filterName, $page);
        $filterCondition = $filterClause->condition;
        $cacheKey = 'filter_' . $filterName . '_' . $userId;
        // we use the cache pool only for fetching lines filtered only by
        // permissions
        $cacheApplicable = $filterClause->cacheApplicable;
        $cached = $cacheApplicable ? $this->cacheGet($cacheKey) : null;

        if (is_array($cached)
            and is_array($cached['pre_counters'] ?? null)
            and is_array($cached['list_of_dates'] ?? null)
        ) {
            $preCounters = $cached['pre_counters'];
            $listOfDates = $cached['list_of_dates'];
        } else {
            // These thresholds are genuinely relative to the real wall
            // clock, deliberately NOT PIWIGO_TEST_NOW-frozen, so this uses
            // a bare `new \DateTime()` (real, unfrozen wall clock -- the
            // same idiom used elsewhere in this codebase, e.g.
            // Admin\StatsPageRenderer), not `Env::now()`.
            $thresholds = [];
            foreach (array_keys($labelForThreshold) as $threshold) {
                $thresholds[$threshold] = new DateTime()->modify($this->intervalForThreshold($threshold))->format('Y-m-d H:i:s');
            }

            $filterRows = $this->repo->findDistinctImageRows(["{$dqlField} AS date"], $filterCondition);

            $listOfDates = [];
            $preCounters = [];

            foreach ($filterRows as $row) {
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
                $ym = $y . '-' . $m;

                $day_count = $listOfDates[$y]['months'][$ym]['days'][$dateWithoutTime]['count'] ?? 0;
                $listOfDates[$y]['months'][$ym]['days'][$dateWithoutTime]['count'] = $day_count + 1;
                $month_count = $listOfDates[$y]['months'][$ym]['count'] ?? 0;
                $listOfDates[$y]['months'][$ym]['count'] = $month_count + 1;
                $year_count = $listOfDates[$y]['count'] ?? 0;
                $listOfDates[$y]['count'] = $year_count + 1;
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
            // $preCounters is an int-valued map when this method counted
            // it, but comes back from the persistent cache pool as an
            // untyped array, so the value is narrowed here rather than
            // carried as mixed into DateFilterCounter (and from there
            // into the two `0 ==` comparisons the template makes per row).
            $preCounter = $preCounters[$threshold] ?? 0;
            $counters[$threshold] = new DateFilterCounter(
                label: $labelForThreshold[$threshold],
                counter: is_int($preCounter) ? $preCounter : 0,
            );
        }

        // $listOfDates may have come from the persistent cache above, which
        // stores it as plain mixed data — validate each nesting level
        // defensively rather than trusting its shape. This pass is also
        // where the counted arrays are frozen into DateFilterYear/Month/Day:
        // the freeze happens *after* the cache read, never before, so no
        // object is ever serialized into the cache pool.
        $years = [];
        foreach (array_keys($listOfDates) as $y) {
            $yearBucket = $listOfDates[$y] ?? null;
            if (! is_array($yearBucket)) {
                continue;
            }

            $months = [];
            $monthsBucket = $yearBucket['months'] ?? null;
            if (is_array($monthsBucket)) {
                foreach (array_keys($monthsBucket) as $ym) {
                    $monthBucket = $monthsBucket[$ym] ?? null;
                    if (! is_array($monthBucket)) {
                        continue;
                    }

                    $days = [];
                    $daysBucket = $monthBucket['days'] ?? null;
                    if (is_array($daysBucket)) {
                        foreach (array_keys($daysBucket) as $ymd) {
                            $dayBucket = $daysBucket[$ymd] ?? null;
                            if (! is_array($dayBucket)) {
                                continue;
                            }
                            $days[$ymd] = new DateFilterDay(
                                label: DateHelper::formatDate($ymd),
                                count: self::filterCount($dayBucket['count'] ?? null),
                            );
                        }
                    }

                    [, $m] = explode('-', (string) $ym);
                    $monthName = $langMonth[(int) $m] ?? null;
                    $monthName = is_string($monthName) ? $monthName : '';
                    $months[$ym] = new DateFilterMonth(
                        label: $monthName . ' ' . $y,
                        count: self::filterCount($monthBucket['count'] ?? null),
                        days: $days,
                    );
                }
            }

            $years[$y] = new DateFilterYear(
                label: $this->lang->t('year %d', $y),
                count: self::filterCount($yearBucket['count'] ?? null),
                months: $months,
            );
        }
        krsort($years);

        return new DateFilterOptions(
            counters: $counters,
            listOfDates: $years,
        );
    }

    /**
     * A "how many photos" count for one sidebar filter row -- a
     * day/month/year date bucket, or an author/added-by group.
     *
     * Every one of those row sets is read back out of the persistent cache
     * pool, where it is plain mixed data, and the DBAL COUNT() aggregate is
     * itself int-or-string depending on driver. Anything non-numeric is a
     * corrupt or stale entry and counts as zero, which is what the
     * templates rendered for it before these rows were typed.
     */
    private static function filterCount(mixed $count): int
    {
        return is_numeric($count) ? (int) $count : 0;
    }

    private function intervalForThreshold(string $threshold): string
    {
        return match ($threshold) {
            '24h' => '-24 hours',
            '7d' => '-7 days',
            '30d' => '-30 days',
            '3m' => '-3 months',
            '6m' => '-6 months',
            '12m' => '-12 months',
            default => '-0 days',
        };
    }

    /**
     * Returns the SQL WHERE clause to be used to build filter values.
     *
     * @param array<string, mixed> $page see render()'s own docblock --
     *   by-ref because getItemsForFilter() caches its computed
     *   intersection back onto $page['search_details'] for later calls
     *   within the same render() invocation to reuse (see that method's
     *   own comment); losing that write-back across calls would still be
     *   correct, just slower (a fresh array_intersect() per filter
     *   instead of a cache hit).
     *
     * Returns a bound `ic.image IN (:x)` DQL-shaped condition against
     * every real caller's own DQL query, alongside an explicit
     * `cacheApplicable` discriminant every caller below reads instead of
     * sniffing the condition's own SQL text -- see
     * {@see SearchFilterClause}'s own docblock for why.
     */
    private function getClauseForFilter(string $filterName, array &$page): SearchFilterClause
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
            $forbiddenCondition = $forbidden instanceof SqlCondition ? $forbidden : SqlCondition::fromRawSql('');

            return new SearchFilterClause($forbiddenCondition, true);
        }

        // getItemsForFilter() ultimately pulls its values from
        // $page['search_details']['image_ids_for_filter'], which is declared
        // array<string, mixed> (getRegularSearchResults()'s return shape) — in
        // practice always image ids, narrowed to int here for binding.
        $otherFiltersItemInts = array_map(
            static fn (int|string $v): int => (int) $v,
            $otherFiltersItems
        );

        return new SearchFilterClause(
            SqlCondition::fromRawSql(
                'ic.image IN (:otherFiltersItems)',
                [
                    'otherFiltersItems' => $otherFiltersItemInts,
                ],
                [
                    'otherFiltersItems' => ArrayParameterType::INTEGER,
                ],
            ),
            false,
        );
    }

    /**
     * Returns the list of items (image_ids) to be used to build filter
     * values for a given filter. Depends on the other filters. Use a cache
     * to avoid computing the same large array_intersect several times.
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
            $functionStart = TimingHelper::getMoment();

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
            $debugMsg .= ', time = ' . TimingHelper::getElapsedTime($functionStart, TimingHelper::getMoment());
            $logger->debug($debugMsg);

            if ($otherFiltersItems === []) {
                $otherFiltersItems = [NoMatchSentinel::ID];
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

    /**
     * Coerces a counter map -- filetype/rating/ratio bucket counts -- back
     * to `array<array-key, int>`.
     *
     * Needed on two paths, both of which lose the value type: a cache read
     * (the pool hands back `mixed`, and `is_array()` proves nothing about
     * the values) and a raw DBAL row's `counter` column. Without it the
     * counts reach `search_filters.inc.latte` as `mixed`, which is what
     * left nine `0 == $count` comparisons undecidable (P58-B3).
     *
     * A non-numeric entry becomes 0 rather than being dropped, so the
     * bucket keeps its slot: the template renders one control per bucket
     * and a missing key would shift the set, not just its label.
     *
     * @return array<array-key, int>
     */
    private static function toCounterMap(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $key => $value) {
            $map[$key] = is_numeric($value) ? (int) $value : 0;
        }

        return $map;
    }
}
