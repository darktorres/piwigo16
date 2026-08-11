<?php

declare(strict_types=1);

namespace Piwigo\Search;

use DateTime;
use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Auth\AccessControl;
use Piwigo\Cache\CachePools;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\Projection\Category;
use Piwigo\Common\Enum\Section;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\FilterViewDefinition;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\DateHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\TemplateInterface;
use Piwigo\Core\TimingHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\NoMatchSentinel;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
use Piwigo\Search\Projection\SearchAlbumsFoundPageContext;
use Piwigo\Search\Projection\SearchDateFilterPageContext;
use Piwigo\Search\Projection\SearchFilterPageContext;
use Piwigo\Search\Projection\SearchTagsFoundPageContext;
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
 * {@see \Piwigo\Cache\CachePools::searchResults()} (30s TTL).
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
        private TemplateInterface $template,
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
    ) {}

    private function cacheGet(string $key): mixed
    {
        $item = CachePools::searchResults()->getItem($key);

        return $item->isHit() ? $item->get() : null;
    }

    private function cacheSet(string $key, mixed $value): void
    {
        $pool = CachePools::searchResults();
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
     * Returns the search id resolved by
     * SearchService::getValidatedSearchArray() (null when this page isn't
     * a search results page) -- the one real caller (GalleryController)
     * passes it straight into its own HistoryService::logVisit() call.
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

        $filtersViewsRaw = $this->currentConfig->filtersViews->filters ?? $this->currentConfig->defaultFiltersViews;

        // filtersViews->filters (unlike the old raw filters_views config
        // value) never commingles the sibling 'last_filters_conf' flag with
        // the per-filter entries -- FilterViewsSelection keeps it as its
        // own field -- so every entry here is already a real per-filter
        // definition; no filtering needed, just the VO -> array unwrap this
        // method's own boolean-access rewrite below needs.
        $filtersViews = array_map(static fn (FilterViewDefinition $d): array => $d->toArray(), $filtersViewsRaw);

        // we add the search_details emptiness check in this condition
        // because it only applies to regular search, not the legacy
        // qsearch (SectionContext::searchDetails stays [] whenever
        // SectionPopulator's own search branch took the qsearch path
        // instead -- see that class's search-section handling). As
        // Piwigo 14 will still be able to show an old quicksearch
        // result, we must check this condition too.
        if ($page['section'] !== Section::Search || $page['search_details'] === []) {
            return null;
        }

        $displayFilters = $filtersViews;

        // Every entry has an 'access' key -- toArray() above always
        // produces both fields, unlike the old raw config-array shape this
        // isset() guard used to defend against.
        foreach ($filtersViews as $filtName => $filtConf) {
            if ($filtConf['access'] === 'everybody' or ($filtConf['access'] === 'admins-only' and $this->accessControl->isAdmin()) or ($filtConf['access'] === 'registered-users' and $this->accessControl->isClassicUser())) {
                $displayFilters[$filtName]['access'] = true;
            } else {
                $displayFilters[$filtName]['access'] = false;
            }
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
        if (! isset($mySearch['fields']) || ! is_array($mySearch['fields'])) {
            $mySearch['fields'] = [];
        }

        /** @var array<string, mixed> $searchFields */
        $searchFields = &$mySearch['fields'];

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

        // we want filters to be filled with values related to current items
        // ONLY IF we have some filters filled
        if ((bool) $page['search_details']['has_filters_filled']) {
            $searchItems = [NoMatchSentinel::ID];
            if ($page['items'] !== []) {
                $searchItems = $page['items'];
            }

            $searchItemsClause = 'image_id IN (' . implode(',', $searchItems) . ')';
        } else {
            $searchItemsClause = '1=1';
        }
        unset($searchItemsClause); // unused local; not referenced downstream

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

            $tags = $filterTags;

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
                $this->lang->load('help_quick_search.lang');
            }
        }

        if (isset($searchFields['author']) and (bool) $displayFilters['author']['access']) {
            $filterCondition = $this->getClauseForFilter('author', $page);
            $groupCondition = SqlCondition::combine('AND', $filterCondition, new SqlCondition('i.author IS NOT NULL'));

            if (! str_starts_with($filterCondition->sql, 'ic.imageId IN')) {
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
                $authors[] = $authorRow;

                $authorName = $authorRow['author'] ?? null;
                if (is_string($authorName)) {
                    $authorNames[] = $authorName;
                }
            }
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
                'i.dateAvailable',
                [
                    '24h' => $this->lang->t('last 24 hours'),
                    '7d' => $this->lang->t('last 7 days'),
                    '30d' => $this->lang->t('last 30 days'),
                    '3m' => $this->lang->t('last 3 months'),
                    '6m' => $this->lang->t('last 6 months'),
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
                'i.dateCreation',
                [
                    '7d' => $this->lang->t('last 7 days'),
                    '30d' => $this->lang->t('last 30 days'),
                    '3m' => $this->lang->t('last 3 months'),
                    '6m' => $this->lang->t('last 6 months'),
                    '12m' => $this->lang->t('last 12 months'),
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

            if (! str_starts_with($filterCondition->sql, 'ic.imageId IN')) {
                // we use the cache pool only for fetching lines filtered
                // only by permissions
                $cacheKey = 'added_by_rows_' . $userId;
                $filterRows = $this->cacheGet($cacheKey);
                if (! is_array($filterRows)) {
                    $filterRows = $this->repo->countImagesGroupedBy('i.addedBy', 'added_by_id', $filterCondition, true);
                    $this->cacheSet($cacheKey, $filterRows);
                }
            } else {
                $filterRows = $this->repo->countImagesGroupedBy('i.addedBy', 'added_by_id', $filterCondition, true);
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
                // now let's find the usernames of added_by users. added_by_id
                // is a native ?int here (DQL-hydrated) -- is_int()||is_string(),
                // not is_string() alone, or every id gets silently filtered
                // out; UserService::getUsernamesByIds()/the $usernameOf
                // lookup below both accept either (PHP coerces a numeric
                // array key either way).
                foreach ($addedBy as $row) {
                    $rowAddedById = $row['added_by_id'] ?? null;
                    if (is_int($rowAddedById) || is_string($rowAddedById)) {
                        $userIds[] = (string) $rowAddedById;
                    }
                }

                // Reuses UserService::getUsernamesByIds() -- the same
                // id => username map shape this block already builds.
                $usernameOf = $this->userService->getUsernamesByIds($userIds);

                foreach (array_keys($addedBy) as $addedByIdx) {
                    $addedById = $addedBy[$addedByIdx]['added_by_id'] ?? null;
                    if (! is_int($addedById) && ! is_string($addedById)) {
                        continue;
                    }
                    $addedBy[$addedByIdx]['added_by_name'] = $usernameOf[$addedById] ?? 'user #' . $addedById . ' (deleted)';
                }
            }

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

                // Uses the same live PermissionService condition every
                // other visibility check in this class uses.
                //
                // `categories` is a single-table DQL query, no image join
                // -- the category ids are bound as a parameter, not
                // spliced into the SQL.
                $permissionCondition = $this->permissionService->getPermissionCriteria()
                    ->visibleCategoriesCondition('c.id');
                $catWordsInts = array_map(static fn (int|string $v): int => (int) $v, $catWords);
                $idsCondition = new SqlCondition(
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
                        $map[$row['ext']] = $row['counter'] ?? 0;
                    }
                }

                return $map;
            };

            $allExts = $this->cacheGet($cacheKey);
            if (! is_array($allExts)) {
                $allExts = $extToCounterMap($this->repo->countImagesGroupedBy("SUBSTRING_INDEX(i.path, '.', -1)", 'ext', $allExtsCondition, true));
                $this->cacheSet($cacheKey, $allExts);
            }

            if (str_starts_with($filterCondition->sql, 'ic.imageId IN')) {
                $filteredExts = $extToCounterMap($this->repo->countImagesGroupedBy("SUBSTRING_INDEX(i.path, '.', -1)", 'ext', $filterCondition, true));

                $exts = [];
                foreach ($allExts as $ext => $counter) {
                    $exts[$ext] = $filteredExts[$ext] ?? 0;
                }

                $filetypes = $exts;
            } else {
                $filetypes = $allExts;
            }
        } elseif (isset($searchFields['filetypes'])) {
            unset($searchFields['filetypes']);
        }

        // For rating
        if ($this->currentConfig->rateEnabled) {
            $show_filter_ratings = true;

            if (isset($searchFields['ratings']) and (bool) $displayFilters['rating']['access']) {
                $filterCondition = $this->getClauseForFilter('ratings', $page);

                $cacheKey = 'ratings_' . $userId;
                $cacheApplicable = ! str_starts_with($filterCondition->sql, 'ic.imageId IN');
                $ratings = $cacheApplicable ? $this->cacheGet($cacheKey) : null;

                if (! is_array($ratings)) {
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
            } elseif (isset($searchFields['ratings'])) {
                unset($searchFields['ratings']);
            }
        } else {
            $show_filter_ratings = false;
            if (isset($searchFields['ratings'])) {
                unset($searchFields['ratings']);
            }
        }

        // For filesize
        if (isset($searchFields['filesize_min']) && isset($searchFields['filesize_max']) and (bool) $displayFilters['file_size']['access']) {
            $filterCondition = $this->getClauseForFilter('filesize', $page);

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

        } elseif (isset($searchFields['filesize_min']) && isset($searchFields['filesize_max']) and ! ((bool) $displayFilters['file_size']['access'])) {
            unset($searchFields['filesize_min']);
            unset($searchFields['filesize_max']);
        }

        if (isset($searchFields['ratios']) and (bool) $displayFilters['ratio']['access']) {
            $filterCondition = $this->getClauseForFilter('ratios', $page);

            $cacheKey = 'ratios_' . $userId;
            $cacheApplicable = ! str_starts_with($filterCondition->sql, 'ic.imageId IN');
            $ratios = $cacheApplicable ? $this->cacheGet($cacheKey) : null;

            if (! is_array($ratios)) {
                $notNullCondition = SqlCondition::combine('AND', $filterCondition, new SqlCondition('i.width IS NOT NULL AND i.height IS NOT NULL'));
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
        } elseif (isset($searchFields['ratios'])) {
            unset($searchFields['ratios']);
        }

        if (isset($searchFields['height_min']) and isset($searchFields['height_max']) and (bool) $displayFilters['height']['access']) {
            $filterCondition = $this->getClauseForFilter('height', $page);

            $notNullCondition = SqlCondition::combine('AND', $filterCondition, new SqlCondition('i.height IS NOT NULL'));

            if (! str_starts_with($filterCondition->sql, 'ic.imageId IN')) {
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

        } elseif (isset($searchFields['height_min']) && isset($searchFields['height_max']) and ! ((bool) $displayFilters['height']['access'])) {
            unset($searchFields['height_min']);
            unset($searchFields['height_max']);
        }

        if (isset($searchFields['width_min']) and isset($searchFields['width_max']) and (bool) $displayFilters['width']['access']) {
            $filterCondition = $this->getClauseForFilter('width', $page);

            $notNullCondition = SqlCondition::combine('AND', $filterCondition, new SqlCondition('i.width IS NOT NULL'));

            if (! str_starts_with($filterCondition->sql, 'ic.imageId IN')) {
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

        } elseif (isset($searchFields['width_min']) && isset($searchFields['width_max']) and ! ((bool) $displayFilters['width']['access'])) {
            unset($searchFields['width_min']);
            unset($searchFields['width_max']);
        }

        $search_id = $page['search'] ?? null;
        $search_id = is_string($search_id) ? $search_id : null;

        $template->assignContext(new SearchFilterPageContext(
            displayFilter: $filtersViews,
            tags: $tags,
            authors: $authors,
            addedBy: $addedBy,
            fullnameOf: $fullname_of_json,
            filetypes: $filetypes,
            showFilterRatings: $show_filter_ratings,
            rating: $rating,
            filesize: $filesize,
            ratios: $ratios,
            height: $height,
            width: $width,
            gp: json_encode($mySearch),
            searchId: $search_id,
        ));

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
     * $matching_cat_ids). searchAllwords()'s category-name/comment match
     * applies no forbidden-categories condition of its own -- $cat_ids
     * can genuinely contain a category this user can't see, so this
     * filters against CurrentUser::get()->forbiddenCategories before
     * loading row data via CategoryRepository::findFullCategoriesByIds().
     * This permission filter is real and load-bearing, not redundant.
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
        // config) -- is_int()||is_string(), not is_string() alone, or
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

        // CategoryRepository::findFullCategoriesByIds() returns typed
        // Category projections -- unboxed to array here since
        // nameCompare()'s signature (shared with every other name-sort
        // call site in this project) takes array<string, mixed>.
        $cats = array_map(
            static fn (Category $cat): array => $cat->toArray(),
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
            $template->assignContext(new SearchAlbumsFoundPageContext($albumsFound));
        }
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
            $template->assignContext(new SearchTagsFoundPageContext($tagsFound));
        }
    }

    /**
     * Shared logic for the date_posted/date_created filter blocks -- same
     * shape (thresholds → per-image bucket counters → year/month/day tree),
     * differing only in which date column, threshold set, and template
     * variable names they use.
     *
     * @param array<int, string> $langMonth
     * @param string $dqlField DQL property path (`i.dateAvailable`/
     *   `i.dateCreation`)
     * @param array<string, string> $labelForThreshold keyed by threshold id
     *   (e.g. '24h', '7d'), in display order
     * @param 'LIST_DATE_POSTED'|'LIST_DATE_CREATED' $listTemplateVar
     * @param 'DATE_POSTED'|'DATE_CREATED' $counterTemplateVar
     * @param array<string, mixed> $page see render()'s own docblock
     */
    private function renderDateFilter(
        array $langMonth,
        string $userId,
        string $filterName,
        string $dqlField,
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
        $cacheApplicable = ! str_starts_with($filterCondition->sql, 'ic.imageId IN');
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
            $yearBucket['label'] = $this->lang->t('year %d', $y);

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
                            $dayBucket['label'] = DateHelper::formatDate($ymd);
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

        $template->assignContext(new SearchDateFilterPageContext(
            listKey: $listTemplateVar,
            counterKey: $counterTemplateVar,
            listOfDates: $listOfDates,
            counters: $counters,
        ));
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
     * Returns a bound `ic.imageId IN (:x)` DQL-shaped condition against
     * every real caller's own DQL query -- every caller below checks
     * `str_starts_with($condition->sql, 'ic.imageId IN')`.
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
            'ic.imageId IN (:otherFiltersItems)',
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
}
