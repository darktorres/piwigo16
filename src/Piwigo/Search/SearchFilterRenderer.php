<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Piwigo\Cache\PersistentCache;
use Piwigo\Category\CategoryRepository;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Template\Template;

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
final class SearchFilterRenderer
{
    public function render(): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $lang
         * @var array<string, mixed> $page
         * @var Template $template
         * @var array<string, mixed> $user
         */
        global $conf, $lang, $page, $persistent_cache, $template, $user;
        if (! $persistent_cache instanceof PersistentCache) {
            fatal_error('persistent cache not initialized');
        }

        $filtersViewsConf = conf_get_param('filters_views', null);
        if (is_array($filtersViewsConf) || is_string($filtersViewsConf)) {
            $filtersViewsRaw = safe_unserialize($filtersViewsConf);
        } else {
            $filtersViewsRaw = $conf['default_filters_views'];
        }

        if (! is_array($filtersViewsRaw)) {
            $filtersViewsRaw = $conf['default_filters_views'];
            if (! is_array($filtersViewsRaw)) {
                $filtersViewsRaw = [];
            }
        }

        // 'last_filters_conf' is a lone boolean flag stored alongside the
        // per-filter settings in this config value (see
        // admin/configuration.php); every other entry is a settings array.
        // This method only ever reads the per-filter arrays by name, so
        // drop the flag here to give $filtersViews a uniform, narrow shape.
        /** @var array<string, array<string, mixed>> $filtersViews */
        $filtersViews = array_filter($filtersViewsRaw, is_array(...));

        $template->assign('display_filter', $filtersViews);

        // we add isset($page['search_details']) in this condition because it
        // only applies to regular search, not the legacy qsearch. As Piwigo
        // 14 will still be able to show an old quicksearch result, we must
        // check this condition too.
        if ($page['section'] !== 'search' || ! isset($page['search_details']) || ! is_array($page['search_details'])) {
            return;
        }

        $displayFilters = $filtersViews;

        foreach ($filtersViews as $filtName => $filtConf) {
            if (isset($filtConf['access'])) {
                if ($filtConf['access'] === 'everybody' or ($filtConf['access'] === 'admins-only' and is_admin()) or ($filtConf['access'] === 'registered-users' and is_classic_user())) {
                    $displayFilters[$filtName]['access'] = true;
                } else {
                    $displayFilters[$filtName]['access'] = false;
                }
            }
        }

        // $user['id']/$user['cache_update_time'] key every persistent-cache
        // entry built by the filter blocks below; narrow them once to real
        // strings.
        $userId = is_scalar($user['id'] ?? null) ? (string) $user['id'] : '';
        $userCacheUpdateTime = is_scalar($user['cache_update_time'] ?? null) ? (string) $user['cache_update_time'] : '';

        // $lang['month'] is the language file's month-index (1-12) to name
        // map; narrow it once for the date_posted/date_created breakdowns
        // below.
        $langMonthRaw = is_array($lang['month'] ?? null) ? $lang['month'] : [];
        $langMonth = [];
        foreach ($langMonthRaw as $monthIndex => $monthName) {
            if (is_int($monthIndex) && is_string($monthName)) {
                $langMonth[$monthIndex] = $monthName;
            }
        }

        $searchId = $page['search'] ?? null;
        $searchId = (is_int($searchId) || is_string($searchId)) ? $searchId : '';
        $mySearch = get_search_array($searchId);
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

        $page['search_details']['forbidden'] = get_sql_condition_FandF(
            [
                'forbidden_categories' => 'category_id',
                'visible_categories' => 'category_id',
                'visible_images' => 'id',
            ],
            "\n  AND"
        );

        // we want filters to be filled with values related to current items
        // ONLY IF we have some filters filled
        if ((bool) $page['search_details']['has_filters_filled']) {
            $searchItems = [-1];
            if (isset($page['items']) && is_array($page['items']) && $page['items'] !== []) {
                /** @var list<int|string|float|bool> $searchItems */
                $searchItems = array_values(array_filter($page['items'], is_scalar(...)));
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

            // TODO calling get_available_tags(), with lots of
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

            $otherFiltersItems = get_items_for_filter('tags');
            if ($otherFiltersItems === false) {
                $filterTags = get_available_tags();
                usort($filterTags, tag_alpha_compare(...));
            } else {
                $tagFilterItems = [];
                foreach ($otherFiltersItems as $otherFilterItem) {
                    if (is_numeric($otherFilterItem)) {
                        $tagFilterItems[] = (int) $otherFilterItem;
                    }
                }

                $filterTags = get_common_tags($tagFilterItems, 0);

                // the user may have started a search on 2 or more tags that
                // have no intersection. In this case, $searchItems is empty
                // and get_common_tags returns nothing. We should still
                // display the list of selected tags. We have to "force"
                // them in the list.
                $missingTagIds = array_diff($tagWords, $extractTagIds($filterTags));

                if (count($missingTagIds) > 0) {
                    $filterTags = array_merge(get_available_tags($missingTagIds), $filterTags);
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
                load_language('help_quick_search.lang');
            }
        }

        if (isset($searchFields['author']) and (bool) $displayFilters['author']['access']) {
            $filterClause = get_clause_for_filter('author');

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
                    $filterRows = query2array($query);
                    $persistent_cache->set($cacheKey, $filterRows);
                }
            } else {
                $filterRows = query2array($query);
            }

            if (! is_array($filterRows)) {
                // the persistent cache should only ever hold what
                // query2array() just produced above; re-run the query if a
                // corrupted entry slipped through.
                $filterRows = query2array($query);
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
                'DATE_POSTED'
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
                'DATE_CREATED'
            );
        } elseif (isset($searchFields['date_created'])) {
            unset($searchFields['date_created']);
        }

        if (isset($searchFields['added_by']) and (bool) $displayFilters['added_by']['access']) {
            $filterClause = get_clause_for_filter('added_by');

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
                    $filterRows = query2array($query);
                    $persistent_cache->set($cacheKey, $filterRows);
                }
            } else {
                $filterRows = query2array($query);
            }

            if (! is_array($filterRows)) {
                // the persistent cache should only ever hold what
                // query2array() just produced above; re-run the query if a
                // corrupted entry slipped through.
                $filterRows = query2array($query);
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

                // $conf['user_fields'] maps generic field names to actual
                // DB columns; fall back to the generic names, matching
                // functions_mail.inc.php.
                $confUserFields = $conf['user_fields'] ?? null;
                $confUserFields = is_array($confUserFields) ? $confUserFields : [];
                $userFieldId = is_string($confUserFields['id'] ?? null) ? $confUserFields['id'] : 'id';
                $userFieldUsername = is_string($confUserFields['username'] ?? null) ? $confUserFields['username'] : 'username';

                $query = '
SELECT
    ' . $userFieldId . ' AS id,
    ' . $userFieldUsername . ' AS username
  FROM ' . Tables::users() . '
  WHERE ' . $userFieldId . ' IN (' . implode(',', $userIds) . ')
;';
                $usernameOf = query2array($query, 'id', 'username');

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
                $result = pwg_query($query);

                while ((bool) ($row = pwg_db_fetch_assoc($result))) {
                    if ($row['id'] === null || $row['uppercats'] === null) {
                        continue;
                    }

                    $catDisplayName = get_cat_display_name_cache(
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
            $filterClause = get_clause_for_filter('filetypes');

            // get all file extensions for this user in the gallery,
            // whatever the current filters
            $cacheKey = $persistent_cache->make_key('file_exts' . $userId . $userCacheUpdateTime);
            // Always a string here -- unconditionally set earlier in this
            // method, before any branching.
            $searchDetailsForbidden = $page['search_details']['forbidden'];
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
                $allExts = query2array($allExtsQuery, 'ext', 'counter');
                $persistent_cache->set($cacheKey, $allExts);
            }

            if (! is_array($allExts)) {
                // the persistent cache should only ever hold what
                // query2array() just produced above; re-run the query if a
                // corrupted entry slipped through.
                $allExts = query2array($allExtsQuery, 'ext', 'counter');
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
                $filteredExts = query2array($query, 'ext', 'counter');

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
        if ((bool) $conf['rate']) {
            $template->assign('SHOW_FILTER_RATINGS', true);

            if (isset($searchFields['ratings']) and (bool) $displayFilters['rating']['access']) {
                $filterClause = get_clause_for_filter('ratings');

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

                    $filterRows = query2array($query);

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
            $filterClause = get_clause_for_filter('filesize');

            $filesizes = [];

            $query = '
SELECT
    DISTINCT id,
    filesize
  FROM ' . Tables::images() . ' AS i
    JOIN ' . Tables::imageCategory() . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filterClause . '
;';
            $result = pwg_query($query);
            while ((bool) ($row = pwg_db_fetch_assoc($result))) {
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
            $filterClause = get_clause_for_filter('ratios');

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

                $filterRows = query2array($query);

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
            $filterClause = get_clause_for_filter('height');

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
                    $filterRows = query2array($query, null, 'height');
                    $persistent_cache->set($cacheKey, $filterRows);
                }
            } else {
                $filterRows = query2array($query, null, 'height');
            }

            if (! is_array($filterRows)) {
                // the persistent cache should only ever hold what
                // query2array() just produced above; re-run the query if a
                // corrupted entry slipped through.
                $filterRows = query2array($query, null, 'height');
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
            $filterClause = get_clause_for_filter('width');

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
                    $filterRows = query2array($query, null, 'width');
                    $persistent_cache->set($cacheKey, $filterRows);
                }
            } else {
                $filterRows = query2array($query, null, 'width');
            }

            if (! is_array($filterRows)) {
                // the persistent cache should only ever hold what
                // query2array() just produced above; re-run the query if a
                // corrupted entry slipped through.
                $filterRows = query2array($query, null, 'width');
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
            $this->renderAlbumsFound($page, $user, $userId);
            $this->renderTagsFound($page);
        }
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
     * $user['forbidden_categories'] (same concept as batch 3a) instead of
     * that JOIN, then CategoryRepository::findFullCategoriesByIds() (batch
     * 4b) for the row data.
     *
     * @param array<string, mixed> $page
     * @param array<string, mixed> $user
     */
    private function renderAlbumsFound(array $page, array $user, string $userId): void
    {
        /** @var Template $template */
        global $template;

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

        $forbiddenCategories = $user['forbidden_categories'] ?? null;
        $allowedCatIds = self::filterAccessibleCategoryIds(
            $catIds,
            is_string($forbiddenCategories) ? $forbiddenCategories : null
        );
        if ($allowedCatIds === []) {
            return;
        }

        $repo = new CategoryRepository(DbConnection::build());
        $cats = $repo->findFullCategoriesByIds($allowedCatIds);
        usort($cats, name_compare(...));

        $albumsFound = [];
        foreach ($cats as $cat) {
            $uppercats = $cat['uppercats'] ?? null;
            if (! is_string($uppercats)) {
                continue;
            }

            $singleLink = false;
            $albumsFound[] = get_cat_display_name_cache(
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
    private function renderTagsFound(array $page): void
    {
        /** @var Template $template */
        global $template;

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

        $tags = get_available_tags($tagIds);
        usort($tags, tag_alpha_compare(...));
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
        string $counterTemplateVar
    ): void {
        /** @var Template $template */
        global $template;

        $filterClause = get_clause_for_filter($filterName);
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
            $thresholds = query2array($query)[0];

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

            $result = pwg_query($query);
            while ((bool) ($row = pwg_db_fetch_assoc($result))) {
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
                            $dayBucket['label'] = format_date($ymd);
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
}
