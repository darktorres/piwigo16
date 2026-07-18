<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Piwigo\Cache\PersistentCache;
use Piwigo\Cache\PersistentFileCache;
use Piwigo\Category\CategoryRepository;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\TemplateInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Section\SectionContext;
use Piwigo\Tag\TagRepository;
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
 * `PersistentCache` (not batch 2's named CachePools) is kept deliberately --
 * it's used identically throughout many other already-shipped files in this
 * codebase, unrelated to this phase's actual goal (deleting `include/`).
 */
final readonly class SearchFilterRenderer
{
    public function __construct(
        private MailerInterface $mailer,
        private HtmlRenderingInterface $htmlRenderer,
        private TemplateInterface $template,
    ) {}

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
        $persistent_cache = \Piwigo\Cache\CurrentPersistentCache::get();
        $template = $this->template;
        if (! $persistent_cache instanceof PersistentCache) {
            $this->htmlRenderer->fatalError('persistent cache not initialized');
        }

        $tagConn = DbConnection::build();
        $tagService = new TagService(new TagRepository($tagConn), new PermissionService(new PermissionRepository($tagConn), new GroupRepository($tagConn)), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())));

        $filtersViewsRaw = \Piwigo\Config\Config::filtersViews() ?? \Piwigo\Config\Config::defaultFiltersViews();

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
                if ($filtConf['access'] === 'everybody' or ($filtConf['access'] === 'admins-only' and \Piwigo\Auth\AccessControl::isAdmin()) or ($filtConf['access'] === 'registered-users' and \Piwigo\Auth\AccessControl::isClassicUser())) {
                    $displayFilters[$filtName]['access'] = true;
                } else {
                    $displayFilters[$filtName]['access'] = false;
                }
            }
        }

        $currentUser = \Piwigo\Users\CurrentUser::get();
        $userId = (string) $currentUser->id;
        $userCacheUpdateTime = $currentUser->cacheUpdateTime;

        $langMonth = \Piwigo\Core\Lang::months();

        $searchId = $page['search'] ?? '';
        $searchConn = DbConnection::build();
        $searchService = new SearchService(
            new SearchRepository($searchConn),
            new PermissionService(new PermissionRepository($searchConn), new GroupRepository($searchConn)),
            new PersistentFileCache(),
            $this->mailer,
            $this->htmlRenderer,
        );
        $resolvedSearchId = null;
        $mySearch = $searchService->getValidatedSearchArray($searchId, $sectionContext->section, $resolvedSearchId);
        if (! is_array($mySearch)) {
            // get_search_array() only returns false when unserialize() fails
            // on malformed data; this method only runs for an
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

        $page['search_details']['forbidden'] = new \Piwigo\Permission\PermissionService(new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()))->getSqlConditionFandF([
            'forbidden_categories' => 'category_id',
            'visible_categories' => 'category_id',
            'visible_images' => 'id',
        ], "\n  AND");

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

            // TODO calling TagService::getAvailableTags(), with lots of
            // photos/albums/tags may cost time, we should reuse the result
            // if already executed (for building the menu for example)

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
            $filterClause = $this->getClauseForFilter('author', $page);

            $query = '
SELECT
    author,
    COUNT(DISTINCT(id)) AS counter
  FROM ' . Tables::images() . ' AS i
    JOIN ' . Tables::imageCategory() . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filterClause . '
    AND author IS NOT NULL
  GROUP BY author
;';

            if (! (bool) preg_match('/^image_id IN/', $filterClause)) {
                // we use persistent_cache only for fetching lines filtered
                // only by permissions
                $cacheKey = $persistent_cache->make_key('filter_author_rows' . $userId . $userCacheUpdateTime);
                $filterRows = null;
                if (! $persistent_cache->get($cacheKey, $filterRows)) {
                    $filterRows = \Piwigo\Db\MysqliDb::query2Array($query);
                    $persistent_cache->set($cacheKey, $filterRows);
                }
            } else {
                $filterRows = \Piwigo\Db\MysqliDb::query2Array($query);
            }

            if (! is_array($filterRows)) {
                // the persistent cache should only ever hold what
                // \Piwigo\Db\MysqliDb::query2Array() just produced above; re-run the query if a
                // corrupted entry slipped through.
                $filterRows = \Piwigo\Db\MysqliDb::query2Array($query);
            }

            // the persistent cache stores this row set as plain mixed data,
            // so validate each row's shape defensively rather than
            // trusting it.
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
                $persistent_cache,
                $langMonth,
                $userId,
                $userCacheUpdateTime,
                'date_posted',
                'date_available',
                [
                    '24h' => l10n('last 24 hours'),
                    '7d' => l10n('last 7 days'),
                    '30d' => l10n('last 30 days'),
                    '3m' => l10n('last 3 months'),
                    '6m' => l10n('last 6 months'),
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
                $persistent_cache,
                $langMonth,
                $userId,
                $userCacheUpdateTime,
                'date_created',
                'date_creation',
                [
                    '7d' => l10n('last 7 days'),
                    '30d' => l10n('last 30 days'),
                    '3m' => l10n('last 3 months'),
                    '6m' => l10n('last 6 months'),
                    '12m' => l10n('last 12 months'),
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
            $filterClause = $this->getClauseForFilter('added_by', $page);

            $query = '
SELECT
    COUNT(DISTINCT(id)) AS counter,
    added_by AS added_by_id
  FROM ' . Tables::images() . ' AS i
    JOIN ' . Tables::imageCategory() . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filterClause . '
  GROUP BY added_by_id
  ORDER BY counter DESC
;';

            if (! (bool) preg_match('/^image_id IN/', $filterClause)) {
                // we use persistent_cache only for fetching lines filtered
                // only by permissions
                $cacheKey = $persistent_cache->make_key('filter_added_by_rows' . $userId . $userCacheUpdateTime);
                $filterRows = null;
                if (! $persistent_cache->get($cacheKey, $filterRows)) {
                    $filterRows = \Piwigo\Db\MysqliDb::query2Array($query);
                    $persistent_cache->set($cacheKey, $filterRows);
                }
            } else {
                $filterRows = \Piwigo\Db\MysqliDb::query2Array($query);
            }

            if (! is_array($filterRows)) {
                // the persistent cache should only ever hold what
                // \Piwigo\Db\MysqliDb::query2Array() just produced above; re-run the query if a
                // corrupted entry slipped through.
                $filterRows = \Piwigo\Db\MysqliDb::query2Array($query);
            }

            // the persistent cache stores this row set as plain mixed data,
            // so validate each row's shape defensively rather than
            // trusting it.
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

                // \Piwigo\Config\Config::userFields() maps generic field names to actual
                // DB columns; fall back to the generic names, matching
                // MailService::userFields().
                $confUserFields = \Piwigo\Config\Config::userFields();
                $userFieldId = is_string($confUserFields['id'] ?? null) ? $confUserFields['id'] : 'id';
                $userFieldUsername = is_string($confUserFields['username'] ?? null) ? $confUserFields['username'] : 'username';

                $query = '
SELECT
    ' . $userFieldId . ' AS id,
    ' . $userFieldUsername . ' AS username
  FROM ' . Tables::users() . '
  WHERE ' . $userFieldId . ' IN (' . implode(',', $userIds) . ')
;';
                $usernameOf = \Piwigo\Db\MysqliDb::query2Array($query, 'id', 'username');

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

                $query = '
SELECT
    id,
    uppercats
  FROM ' . Tables::categories() . '
    INNER JOIN ' . Tables::userCacheCategories() . ' ON id = cat_id AND user_id = ' . $userId . '
  WHERE id IN (' . implode(',', $catWords) . ')
;';
                $result = \Piwigo\Db\MysqliDb::query($query);

                while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
                    if ($row['id'] === null || $row['uppercats'] === null) {
                        continue;
                    }

                    $catDisplayName = $this->htmlRenderer->getCatDisplayNameCache(
                        $row['uppercats'],
                        'admin.php?page=album-' // TODO not sure it's relevant to link to admin pages
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
            $filterClause = $this->getClauseForFilter('filetypes', $page);

            // get all file extensions for this user in the gallery,
            // whatever the current filters
            $cacheKey = $persistent_cache->make_key('file_exts' . $userId . $userCacheUpdateTime);
            // Always a string here -- unconditionally set earlier in this
            // method, before any branching; re-narrowed because the
            // getClauseForFilter() by-ref call above widens $page's own
            // per-key types back to the generic array<string, mixed> the
            // parameter itself is typed as.
            $searchDetailsRaw = $page['search_details'];
            $searchDetailsForbiddenRaw = is_array($searchDetailsRaw) ? ($searchDetailsRaw['forbidden'] ?? null) : null;
            $searchDetailsForbidden = is_string($searchDetailsForbiddenRaw) ? $searchDetailsForbiddenRaw : '';
            $allExtsQuery = '
SELECT
    SUBSTRING_INDEX(path, ".", -1) AS ext,
    COUNT(DISTINCT(id)) AS counter
  FROM ' . Tables::images() . ' AS i
    JOIN ' . Tables::imageCategory() . ' AS ic ON ic.image_id = i.id
  WHERE 1=1' . $searchDetailsForbidden . '
  GROUP BY ext
  ORDER BY counter DESC
;';
            $allExts = null;
            if (! $persistent_cache->get($cacheKey, $allExts)) {
                $allExts = \Piwigo\Db\MysqliDb::query2Array($allExtsQuery, 'ext', 'counter');
                $persistent_cache->set($cacheKey, $allExts);
            }

            if (! is_array($allExts)) {
                // the persistent cache should only ever hold what
                // \Piwigo\Db\MysqliDb::query2Array() just produced above; re-run the query if a
                // corrupted entry slipped through.
                $allExts = \Piwigo\Db\MysqliDb::query2Array($allExtsQuery, 'ext', 'counter');
            }

            if ((bool) preg_match('/^image_id IN/', $filterClause)) {
                $query = '
SELECT
    SUBSTRING_INDEX(path, ".", -1) AS ext,
    COUNT(DISTINCT(id)) AS counter
  FROM ' . Tables::images() . ' AS i
    JOIN ' . Tables::imageCategory() . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filterClause . '
  GROUP BY ext
  ORDER BY counter DESC
;';
                $filteredExts = \Piwigo\Db\MysqliDb::query2Array($query, 'ext', 'counter');

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
        if (\Piwigo\Config\Config::rateEnabled()) {
            $template->assign('SHOW_FILTER_RATINGS', true);

            if (isset($searchFields['ratings']) and (bool) $displayFilters['rating']['access']) {
                $filterClause = $this->getClauseForFilter('ratings', $page);

                $cacheKey = $persistent_cache->make_key('filter_ratings' . $userId . $userCacheUpdateTime);

                $ratings = null;
                $setPersistentCache = ! (bool) preg_match('/^image_id IN/', $filterClause) and ! $persistent_cache->get($cacheKey, $ratings);

                if (! isset($ratings)) {
                    $query = '
SELECT
    DISTINCT id,
    rating_score
  FROM ' . Tables::images() . ' AS i
    JOIN ' . Tables::imageCategory() . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filterClause;

                    $filterRows = \Piwigo\Db\MysqliDb::query2Array($query);

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

                    if ($setPersistentCache) {
                        // for this filter, we do not store in cache the
                        // $filterRows: for a big gallery it may take more
                        // than 10MB. It is smarter to store in cache the
                        // result of the computation, which is just around
                        // 100 bytes.
                        $persistent_cache->set($cacheKey, $ratings);
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
            $filterClause = $this->getClauseForFilter('filesize', $page);

            $filesizes = [];

            $query = '
SELECT
    DISTINCT id,
    filesize
  FROM ' . Tables::images() . ' AS i
    JOIN ' . Tables::imageCategory() . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filterClause . '
;';
            $result = \Piwigo\Db\MysqliDb::query($query);
            while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
                if (! is_numeric($row['filesize'])) {
                    continue;
                }
                $bucket = sprintf('%.1f', (float) $row['filesize'] / 1024);
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
                    'min' => (is_numeric($filesizeMin) && (float) $filesizeMin !== 0.0) ? sprintf('%.1f', (float) $filesizeMin / 1024) : $uniqueFilesizes[0],
                    'max' => (is_numeric($filesizeMax) && (float) $filesizeMax !== 0.0) ? sprintf('%.1f', (float) $filesizeMax / 1024) : end($uniqueFilesizes),
                ],
            ];

            $template->assign('FILESIZE', $filesize);
        } elseif (isset($searchFields['filesize_min']) && isset($searchFields['filesize_max']) and ! ((bool) $displayFilters['file_size']['access'])) {
            unset($searchFields['filesize_min']);
            unset($searchFields['filesize_max']);
        }

        if (isset($searchFields['ratios']) and (bool) $displayFilters['ratio']['access']) {
            $filterClause = $this->getClauseForFilter('ratios', $page);

            $cacheKey = $persistent_cache->make_key('filter_ratios' . $userId . $userCacheUpdateTime);

            $ratios = null;
            $setPersistentCache = ! (bool) preg_match('/^image_id IN/', $filterClause) and ! $persistent_cache->get($cacheKey, $ratios);

            if (! isset($ratios)) {
                $query = '
SELECT
    DISTINCT id,
    width,
    height
  FROM ' . Tables::images() . ' as i
    JOIN ' . Tables::imageCategory() . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filterClause . '
    AND width IS NOT NULL
    AND height IS NOT NULL
;';

                $filterRows = \Piwigo\Db\MysqliDb::query2Array($query);

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

                if ($setPersistentCache) {
                    // for this filter, we do not store in cache the
                    // $filterRows: for a big gallery it may take more than
                    // 10MB. It is smarter to store in cache the result of
                    // the computation, which is just around 100 bytes.
                    $persistent_cache->set($cacheKey, $ratios);
                }
            }
            $template->assign('RATIOS', $ratios);
        } elseif (isset($searchFields['ratios'])) {
            unset($searchFields['ratios']);
        }

        if (isset($searchFields['height_min']) and isset($searchFields['height_max']) and (bool) $displayFilters['height']['access']) {
            $filterClause = $this->getClauseForFilter('height', $page);

            $query = '
SELECT
    height
  FROM ' . Tables::images() . ' as i
    JOIN ' . Tables::imageCategory() . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filterClause . '
    AND height IS NOT NULL
  GROUP BY height
  ORDER BY height ASC
;';

            if (! (bool) preg_match('/^image_id IN/', $filterClause)) {
                // we use persistent_cache only for fetching lines filtered
                // only by permissions
                $cacheKey = $persistent_cache->make_key('filter_height_rows' . $userId . $userCacheUpdateTime);
                $filterRows = null;
                if (! $persistent_cache->get($cacheKey, $filterRows)) {
                    $filterRows = \Piwigo\Db\MysqliDb::query2Array($query, null, 'height');
                    $persistent_cache->set($cacheKey, $filterRows);
                }
            } else {
                $filterRows = \Piwigo\Db\MysqliDb::query2Array($query, null, 'height');
            }

            if (! is_array($filterRows)) {
                // the persistent cache should only ever hold what
                // \Piwigo\Db\MysqliDb::query2Array() just produced above; re-run the query if a
                // corrupted entry slipped through.
                $filterRows = \Piwigo\Db\MysqliDb::query2Array($query, null, 'height');
            }

            // the persistent cache stores this row set as plain mixed data,
            // so validate each value defensively rather than trusting it.
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
            $filterClause = $this->getClauseForFilter('width', $page);

            $query = '
SELECT
    width
  FROM ' . Tables::images() . ' as i
    JOIN ' . Tables::imageCategory() . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filterClause . '
    AND width IS NOT NULL
  GROUP BY width
  ORDER BY width ASC
;';

            if (! (bool) preg_match('/^image_id IN/', $filterClause)) {
                // we use persistent_cache only for fetching lines filtered
                // only by permissions
                $cacheKey = $persistent_cache->make_key('filter_width_rows' . $userId . $userCacheUpdateTime);
                $filterRows = null;
                if (! $persistent_cache->get($cacheKey, $filterRows)) {
                    $filterRows = \Piwigo\Db\MysqliDb::query2Array($query, null, 'width');
                    $persistent_cache->set($cacheKey, $filterRows);
                }
            } else {
                $filterRows = \Piwigo\Db\MysqliDb::query2Array($query, null, 'width');
            }

            if (! is_array($filterRows)) {
                // the persistent cache should only ever hold what
                // \Piwigo\Db\MysqliDb::query2Array() just produced above; re-run the query if a
                // corrupted entry slipped through.
                $filterRows = \Piwigo\Db\MysqliDb::query2Array($query, null, 'width');
            }

            // the persistent cache stores this row set as plain mixed data,
            // so validate each value defensively rather than trusting it.
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
            \Piwigo\Users\CurrentUser::get()->forbiddenCategories
        );
        if ($allowedCatIds === []) {
            return;
        }

        $repo = new CategoryRepository(DbConnection::build());
        $cats = $repo->findFullCategoriesByIds($allowedCatIds);
        usort($cats, $this->htmlRenderer->nameCompare(...));

        $albumsFound = [];
        foreach ($cats as $cat) {
            $uppercats = $cat['uppercats'] ?? null;
            if (! is_string($uppercats)) {
                continue;
            }

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

        $tagConn = DbConnection::build();
        $tags = new TagService(new TagRepository($tagConn), new PermissionService(new PermissionRepository($tagConn), new GroupRepository($tagConn)), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))
            ->getAvailableTags($tagIds);
        usort($tags, $this->htmlRenderer->tagAlphaCompare(...));
        $tagsFound = [];
        foreach ($tags as $tag) {
            if (! isset($tag['name']) || ! is_string($tag['name'])) {
                continue;
            }

            $url = make_index_url(
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
        PersistentCache $persistentCache,
        array $langMonth,
        string $userId,
        string $userCacheUpdateTime,
        string $filterName,
        string $dbField,
        array $labelForThreshold,
        string $listTemplateVar,
        string $counterTemplateVar,
        TemplateInterface $template,
        array $page
    ): void {
        $filterClause = $this->getClauseForFilter($filterName, $page);
        $cacheKey = $persistentCache->make_key('filter_' . $filterName . $userId . $userCacheUpdateTime);
        // we use persistent_cache only for fetching lines filtered only by
        // permissions
        $cacheApplicable = ! (bool) preg_match('/^image_id IN/', $filterClause);
        $cached = null;
        $hasCached = $cacheApplicable and $persistentCache->get($cacheKey, $cached);

        if ($hasCached
            and is_array($cached)
            and is_array($cached['pre_counters'] ?? null)
            and is_array($cached['list_of_dates'] ?? null)
        ) {
            $preCounters = $cached['pre_counters'];
            $listOfDates = $cached['list_of_dates'];
        } else {
            $intervalExprs = [];
            foreach (array_keys($labelForThreshold) as $threshold) {
                $intervalExprs[] = 'SUBDATE(NOW(), ' . $this->intervalForThreshold($threshold) . ') AS `' . $threshold . '`';
            }
            $query = '
SELECT
    ' . implode(",\n    ", $intervalExprs) . '
;';
            $thresholds = \Piwigo\Db\MysqliDb::query2Array($query)[0];

            $query = '
SELECT
    DISTINCT id,
    ' . $dbField . ' as date
  FROM ' . Tables::images() . ' AS i
    JOIN ' . Tables::imageCategory() . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filterClause . '
;';

            $listOfDates = [];
            $preCounters = [];

            $result = \Piwigo\Db\MysqliDb::query($query);
            while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
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
                $persistentCache->set(
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
            $yearBucket['label'] = l10n('year %d', $y);

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
     */
    private function getClauseForFilter(string $filterName, array &$page): string
    {
        $otherFiltersItems = $this->getItemsForFilter($filterName, $page);
        if ($otherFiltersItems === false) {
            // $page['search_details'] is set (as
            // SearchService::getRegularSearchResults()'s return
            // ['search_details']) in Section\SectionPopulator; 'forbidden' is
            // itself set as a string a few lines above in this same render().
            $searchDetails = is_array($page['search_details'] ?? null) ? $page['search_details'] : [];
            $forbidden = $searchDetails['forbidden'] ?? null;
            return '1=1' . (is_string($forbidden) ? $forbidden : '');
        }

        // getItemsForFilter() ultimately pulls its values from
        // $page['search_details']['image_ids_for_filter'], which is declared
        // array<string, mixed> (getRegularSearchResults()'s return shape) — in
        // practice always image ids, but narrow to scalars here for implode().
        $otherFiltersItemStrings = array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0',
            $otherFiltersItems
        );

        return 'image_id IN (' . implode(',', $otherFiltersItemStrings) . ')';
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
     * @return array<int, mixed>|false array of image_ids, or false
     */
    private function getItemsForFilter(string $filterName, array &$page): false|array
    {
        $logger = \Piwigo\Core\CurrentLogger::get();

        // $page['search_details'] is set (as
        // SearchService::getRegularSearchResults()'s return
        // ['search_details']) in Section\SectionPopulator.
        $searchDetails = is_array($page['search_details'] ?? null) ? $page['search_details'] : [];
        $imageIdsForFilter = is_array($searchDetails['image_ids_for_filter'] ?? null) ? $searchDetails['image_ids_for_filter'] : [];

        $otherFilters = array_diff(array_keys($imageIdsForFilter), [$filterName]);

        if (empty($otherFilters)) {
            return false;
        }

        $cacheKey = md5(implode(',', $otherFilters));

        $filterCache = is_array($searchDetails[__METHOD__] ?? null) ? $searchDetails[__METHOD__] : [];

        if (! isset($filterCache[$cacheKey])) {
            $functionStart = \Piwigo\Core\TimingHelper::getMoment();

            // every entry of $imageIdsForFilter is either a \Piwigo\Db\MysqliDb::query2Array() id
            // list (list<string|null>) or, for 'expert', the already-narrowed
            // result of SearchService::getQuickSearchResults() — normalize
            // each to a plain string-id list here so array_intersect() below
            // has an unambiguous element type (same normalization as
            // SearchService::getRegularSearchResults()).
            $firstFilterRaw = $imageIdsForFilter[array_shift($otherFilters)] ?? null;
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

            $otherFiltersItems = array_unique($otherFiltersItems);

            $debugMsg = '[' . __METHOD__ . '] cache computed for ' . (count($otherFilters) + 1) . ' other filters';
            $debugMsg .= ' (' . count($otherFiltersItems) . ' items)';
            $debugMsg .= ', time = ' . \Piwigo\Core\TimingHelper::getElapsedTime($functionStart, \Piwigo\Core\TimingHelper::getMoment());
            $logger->debug($debugMsg);

            if (empty($otherFiltersItems)) {
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
        /** @var array<int, mixed> $cachedItems */
        return $cachedItems;
    }
}
