<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Piwigo\Category\CategoryRepository;
use Piwigo\Common\Enum\Section;
use Piwigo\Config\Config;
use Piwigo\Core\AppInfo;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Lang\LangService;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Tag\TagService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserRepository;
use Psr\Cache\CacheItemPoolInterface;

final readonly class SearchFilterRenderer
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private DateService $dateService,
        private HtmlService $htmlService,
        private LangService $langService,
        private PermissionService $permissionService,
        private SearchRepository $searchRepository,
        private SearchService $searchService,
        private SearchFilterViewRepository $filterViewRepo,
        private TagService $tagService,
        private UrlService $urlService,
        private UserRepository $userRepository,
        private CacheItemPoolInterface $pool,
    ) {
    }

    public function render(): void
    {
        $template = TemplateRegistry::current();
        $ctx = SectionContextRegistry::current();
        $user = CurrentUser::get()->rawAttributes;
        $userId = is_scalar($user['id'] ?? null) ? (string) $user['id'] : '';
        $userCacheTime = is_scalar($user['cache_update_time'] ?? null) ? (string) $user['cache_update_time'] : '';
        $filters_views = $this->filterViewRepo->listAll();
        if ($filters_views === []) {
            $filters_views = Config::defaultFiltersViews();
        }

        $template->assign('display_filter', $filters_views);

        if (Section::Search === $ctx->section and $ctx->searchDetails !== []) {
            /** @var array<string, mixed> $search_details */
            $search_details = $this->searchService->getSearchDetails();
            if ($search_details === []) {
                $search_details = $ctx->searchDetails;
                $this->searchService->setSearchDetails($search_details);
            }

            // Resolve each persisted filter's `access` role string to a
            // per-user bool. The `last_filters_conf` entry is a bool, not
            // an access-gated filter, so it's skipped.
            /** @var array<string, array{access: bool, default: bool}> $display_filters */
            $display_filters = [];
            foreach ($filters_views as $filt_name => $filt_conf) {
                if (!is_array($filt_conf)) {
                    continue;
                }
                $access = $filt_conf['access'];
                $hasAccess = $access === 'everybody'
                    || ($access === 'admins-only' && $this->permissionService->isAdmin())
                    || ($access === 'registered-users' && $this->permissionService->isClassicUser());
                $display_filters[$filt_name] = ['access' => $hasAccess, 'default' => $filt_conf['default']];
            }

            $searchKey = $ctx->search ?? '';
            $searchRaw = $ctx->search;
            $my_search = $this->searchService->getSearchArray($searchKey);
            /** @var array<string, mixed> $my_search_fields_tmp */
            $my_search_fields_tmp = is_array($my_search['fields'] ?? null) ? $my_search['fields'] : [];
            $my_search['fields'] = $my_search_fields_tmp;
            // Typed view of the same data — used for read-side narrowing.
            // The mutation paths below (intersecting selected words against
            // the available set, dropping filter blocks the user can't
            // access) still update $my_search so the template's hidden
            // form re-emits the same JSON shape the gallery search form
            // accepts.
            $rules = \Piwigo\Search\Rules\SearchRules::fromArray($my_search);

            $forbidden = $this->permissionService->getSqlConditionFandF(
                ['forbidden_categories' => 'category_id', 'visible_categories' => 'category_id', 'visible_images' => 'id'],
                "\n  AND"
            );
            // Search details carry only the SQL-fragment portion for the
            // hash-as-cache-key path; the params/types travel via setForbidden
            // for reuse by getClauseForFilter below.
            $search_details['forbidden'] = $forbidden[0];
            $this->searchService->setForbidden($forbidden);

            if ($search_details['has_filters_filled']) {
                $search_items = [-1];
                $pageItems = $ctx->items;
                if (count($pageItems) > 0) {
                    $search_items = $pageItems;
                }
                $search_items_clause = 'image_id IN (' . implode(',', array_map(fn (int|string $v): string => (string) $v, $search_items)) . ')';
            } else {
                $search_items_clause = '1=1';
            }

            if (isset($my_search['fields']['allwords']) and !($display_filters['words']['access'])) {
                unset($my_search['fields']['allwords']);
            }

            if ($rules->tags !== null) {
                if ($display_filters['tags']['access']) {
                    $filter_tags = [];
                    $other_filters_items = $this->searchService->getItemsForFilter('tags');
                    if (false === $other_filters_items) {
                        $filter_tags = $this->tagService->getAvailableTags();
                        usort($filter_tags, fn (mixed $a, mixed $b): int => $this->htmlService->tagAlphaCompare(is_array($a) ? $a : [], is_array($b) ? $b : []));
                    } else {
                        $filter_tags = $this->tagService->getCommonTags($other_filters_items, 0);
                        $selected_tag_ids_str = array_map(static fn (int $v): string => (string) $v, $rules->tags->tagIds);
                        $available_ids_str    = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column($filter_tags, 'id'));
                        $missing_tag_ids      = array_diff($selected_tag_ids_str, $available_ids_str);

                        if (count($missing_tag_ids) > 0) {
                            $filter_tags = array_merge($this->tagService->getAvailableTags(array_map(intval(...), $missing_tag_ids)), $filter_tags);
                        }
                    }

                    $template->assign('TAGS', $filter_tags);

                    $filter_tag_ids     = count($filter_tags) > 0 ? array_column($filter_tags, 'id') : [];
                    $filter_tag_ids_str = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $filter_tag_ids);
                    $selected_str       = array_map(static fn (int $v): string => (string) $v, $rules->tags->tagIds);
                    $my_search['fields']['tags'] = [
                        'words' => array_values(array_intersect($selected_str, $filter_tag_ids_str)),
                        'mode'  => $rules->tags->mode->value,
                    ];
                } else {
                    unset($my_search['fields']['tags']);
                }
            }

            if (isset($my_search['fields']['expert'])) {
                if (!$display_filters['expert']['access']) {
                    unset($my_search['fields']['expert']);
                } else {
                    $this->langService->loadLanguage('help_quick_search.lang');
                }
            }

            if (isset($my_search['fields']['author']) and $display_filters['author']['access']) {
                [$filter_clause, $filterParams, $filterTypes] = $this->searchService->getClauseForFilter('author');

                if (!preg_match('/^image_id IN/', $filter_clause)) {
                    $cache_key = md5('filter_author_rows' . $userId . $userCacheTime . AppInfo::VERSION);
                    $item      = $this->pool->getItem($cache_key);
                    if ($item->isHit()) {
                        $filter_rows_raw = $item->get();
                        $filter_rows     = $this->normalizeRows(is_array($filter_rows_raw) ? $filter_rows_raw : null);
                    } else {
                        $db_rows = $this->searchRepository->findAuthorsForFilter($filter_clause, $filterParams, $filterTypes);
                        $item->set($db_rows);
                        $item->expiresAfter(86400);
                        $this->pool->save($item);
                        $filter_rows = $this->normalizeRows($db_rows);
                    }
                } else {
                    $filter_rows = $this->searchRepository->findAuthorsForFilter($filter_clause, $filterParams, $filterTypes);
                }

                $author_names = [];
                foreach ($filter_rows as $author) {
                    $author_names[] = $author['author'] ?? '';
                }
                $template->assign('AUTHORS', $filter_rows);

                $author_words_raw = $rules->author === null ? [] : $rules->author->words;
                $author_names_str = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $author_names);
                $my_search['fields']['author'] = [
                    'words' => array_values(array_intersect($author_words_raw, $author_names_str)),
                ];
            } elseif (isset($my_search['fields']['author'])) {
                unset($my_search['fields']['author']);
            }

            if (isset($my_search['fields']['date_posted']) and $display_filters['post_date']['access']) {
                [$filter_clause, $filterParams, $filterTypes] = $this->searchService->getClauseForFilter('date_posted');
                $cache_key     = md5('filter_date_posted' . $userId . $userCacheTime . AppInfo::VERSION);
                $item_dp       = $this->pool->getItem($cache_key);
                $cache_hit_date_posted = !preg_match('/^image_id IN/', $filter_clause) && $item_dp->isHit();
                if ($cache_hit_date_posted) {
                    $date_posted_raw = $item_dp->get();
                } else {
                    $date_posted_raw = ['pre_counters' => [], 'list_of_dates' => []];
                }
                $date_posted  = $this->normalizeDateData(is_array($date_posted_raw) ? $date_posted_raw : null);
                $set_cache_dp = !preg_match('/^image_id IN/', $filter_clause) && !$cache_hit_date_posted;

                if (!$cache_hit_date_posted) {
                    $thresholds = $this->searchRepository->findDatePostedThresholds();

                    $list_of_dates = [];
                    $pre_counters = [];

                    foreach ($this->searchRepository->findImageDatePostedRows($filter_clause, $filterParams, $filterTypes) as $row) {
                        foreach ($thresholds as $threshold => $date_limit) {
                            if ($row['date'] > $date_limit) {
                                $pre_counters[$threshold] = ($pre_counters[$threshold] ?? 0) + 1;
                            }
                        }

                        [$date_without_time] = explode(' ', is_string($row['date'] ?? null) ? $row['date'] : '');
                        [$y, $m] = explode('-', $date_without_time);

                        $ymKey = $y . '-' . $m;
                        $prevDayCount = $list_of_dates[$y]['months'][$ymKey]['days'][$date_without_time]['count'] ?? 0;
                        $list_of_dates[$y]['months'][$ymKey]['days'][$date_without_time]['count'] = $prevDayCount + 1;
                        $prevMonthCount = $list_of_dates[$y]['months'][$ymKey]['count'] ?? 0;
                        $list_of_dates[$y]['months'][$ymKey]['count'] = $prevMonthCount + 1;
                        $list_of_dates[$y]['count'] = ($list_of_dates[$y]['count'] ?? 0) + 1;
                    }

                    $date_posted = ['pre_counters' => $pre_counters, 'list_of_dates' => $list_of_dates];

                    if ($set_cache_dp) {
                        $item_dp->set($date_posted);
                        $item_dp->expiresAfter(86400);
                        $this->pool->save($item_dp);
                    }
                }

                $label_for_threshold = [
                    '24h' => Lang::t('last 24 hours'),
                    '7d' => Lang::t('last 7 days'),
                    '30d' => Lang::t('last 30 days'),
                    '3m' => Lang::t('last 3 months'),
                    '6m' => Lang::t('last 6 months'),
                ];

                $dp_pre = $date_posted['pre_counters'];
                $dp_list = $date_posted['list_of_dates'];

                $counters = [];
                foreach (array_keys($label_for_threshold) as $threshold) {
                    $counters[$threshold] = ['label' => $label_for_threshold[$threshold], 'counter' => $dp_pre[$threshold] ?? 0];
                }

                foreach (array_keys($dp_list) as $y) {
                    $dp_list[$y] = is_array($dp_list[$y]) ? $dp_list[$y] : [];
                    $dp_list[$y]['label'] = Lang::t('year %d', $y);
                    $dpListYMonths = $dp_list[$y]['months'] ?? null;
                    $months = is_array($dpListYMonths) ? $dpListYMonths : [];

                    foreach (array_keys($months) as $ym) {
                        $months[$ym] = is_array($months[$ym]) ? $months[$ym] : [];
                        [, $m] = explode('-', (string) $ym);
                        $month_days = is_array($months[$ym]['days'] ?? null) ? $months[$ym]['days'] : null;
                        $months[$ym]['label'] = Lang::month((int) $m) . ' ' . $y;

                        if ($month_days !== null) {
                            foreach ($month_days as $ymd => $dayEntry) {
                                $dayEntry = is_array($dayEntry) ? $dayEntry : [];
                                $dayEntry['label'] = $this->dateService->formatDate((string) $ymd);
                                $month_days[$ymd] = $dayEntry;
                            }
                            $months[$ym]['days'] = $month_days;
                        }
                        $dp_list[$y]['months'] = $months;
                    }
                }
                krsort($dp_list);

                $template->assign('LIST_DATE_POSTED', $dp_list);
                $template->assign('DATE_POSTED', $counters);
            } elseif (isset($my_search['fields']['date_posted'])) {
                unset($my_search['fields']['date_posted']);
            }

            if (isset($my_search['fields']['date_created']) and $display_filters['creation_date']['access']) {
                [$filter_clause, $filterParams, $filterTypes] = $this->searchService->getClauseForFilter('date_created');
                $cache_key     = md5('filter_date_created' . $userId . $userCacheTime . AppInfo::VERSION);
                $item_dc       = $this->pool->getItem($cache_key);
                $cache_hit_date_created = !preg_match('/^image_id IN/', $filter_clause) && $item_dc->isHit();
                if ($cache_hit_date_created) {
                    $date_created_raw = $item_dc->get();
                } else {
                    $date_created_raw = ['pre_counters' => [], 'list_of_dates' => []];
                }
                $date_created  = $this->normalizeDateData(is_array($date_created_raw) ? $date_created_raw : null);
                $set_cache_dc  = !preg_match('/^image_id IN/', $filter_clause) && !$cache_hit_date_created;

                if (!$cache_hit_date_created) {
                    $thresholds = $this->searchRepository->findDateCreatedThresholds();

                    $list_of_dates = [];
                    $pre_counters = [];

                    foreach ($this->searchRepository->findImageDateCreatedRows($filter_clause, $filterParams, $filterTypes) as $row) {
                        if (!empty($row['date'])) {
                            foreach ($thresholds as $threshold => $date_limit) {
                                if ($row['date'] > $date_limit) {
                                    $pre_counters[$threshold] = ($pre_counters[$threshold] ?? 0) + 1;
                                }
                            }

                            [$date_without_time] = explode(' ', is_string($row['date']) ? $row['date'] : '');
                            [$y, $m] = explode('-', $date_without_time);

                            $ymKey2 = $y . '-' . $m;
                            $prevDayCount2 = $list_of_dates[$y]['months'][$ymKey2]['days'][$date_without_time]['count'] ?? 0;
                            $list_of_dates[$y]['months'][$ymKey2]['days'][$date_without_time]['count'] = $prevDayCount2 + 1;
                            $prevMonthCount2 = $list_of_dates[$y]['months'][$ymKey2]['count'] ?? 0;
                            $list_of_dates[$y]['months'][$ymKey2]['count'] = $prevMonthCount2 + 1;
                            $list_of_dates[$y]['count'] = ($list_of_dates[$y]['count'] ?? 0) + 1;
                        }
                    }

                    $date_created = ['pre_counters' => $pre_counters, 'list_of_dates' => $list_of_dates];

                    if ($set_cache_dc) {
                        $item_dc->set($date_created);
                        $item_dc->expiresAfter(86400);
                        $this->pool->save($item_dc);
                    }
                }

                $label_for_threshold = [
                    '7d' => Lang::t('last 7 days'),
                    '30d' => Lang::t('last 30 days'),
                    '3m' => Lang::t('last 3 months'),
                    '6m' => Lang::t('last 6 months'),
                    '12m' => Lang::t('last 12 months'),
                ];

                $dc_pre = $date_created['pre_counters'];
                $dc_list = $date_created['list_of_dates'];

                $counters = [];
                foreach (array_keys($label_for_threshold) as $threshold) {
                    $counters[$threshold] = ['label' => $label_for_threshold[$threshold], 'counter' => $dc_pre[$threshold] ?? 0];
                }

                foreach (array_keys($dc_list) as $y) {
                    $dc_list[$y] = is_array($dc_list[$y]) ? $dc_list[$y] : [];
                    $dc_list[$y]['label'] = Lang::t('year %d', $y);
                    $dcListYMonths = $dc_list[$y]['months'] ?? null;
                    $months = is_array($dcListYMonths) ? $dcListYMonths : [];

                    foreach (array_keys($months) as $ym) {
                        $months[$ym] = is_array($months[$ym]) ? $months[$ym] : [];
                        [, $m] = explode('-', (string) $ym);
                        $month_days = is_array($months[$ym]['days'] ?? null) ? $months[$ym]['days'] : null;
                        $months[$ym]['label'] = Lang::month((int) $m) . ' ' . $y;

                        if ($month_days !== null) {
                            foreach ($month_days as $ymd => $dayEntry) {
                                $dayEntry = is_array($dayEntry) ? $dayEntry : [];
                                $dayEntry['label'] = $this->dateService->formatDate((string) $ymd);
                                $month_days[$ymd] = $dayEntry;
                            }
                            $months[$ym]['days'] = $month_days;
                        }
                        $dc_list[$y]['months'] = $months;
                    }
                }
                krsort($dc_list);

                $template->assign('LIST_DATE_CREATED', $dc_list);
                $template->assign('DATE_CREATED', $counters);
            } elseif (isset($my_search['fields']['date_created'])) {
                unset($my_search['fields']['date_created']);
            }

            if (isset($my_search['fields']['added_by']) and $display_filters['added_by']['access']) {
                [$filter_clause, $filterParams, $filterTypes] = $this->searchService->getClauseForFilter('added_by');

                if (!preg_match('/^image_id IN/', $filter_clause)) {
                    $cache_key = md5('filter_added_by_rows' . $userId . $userCacheTime . AppInfo::VERSION);
                    $item_ab   = $this->pool->getItem($cache_key);
                    if ($item_ab->isHit()) {
                        $filter_rows_raw = $item_ab->get();
                        $filter_rows     = $this->normalizeRows(is_array($filter_rows_raw) ? $filter_rows_raw : null);
                    } else {
                        $db_rows = $this->searchRepository->findAddedByForFilter($filter_clause, $filterParams, $filterTypes);
                        $item_ab->set($db_rows);
                        $item_ab->expiresAfter(86400);
                        $this->pool->save($item_ab);
                        $filter_rows = $this->normalizeRows($db_rows);
                    }
                } else {
                    $filter_rows = $this->searchRepository->findAddedByForFilter($filter_clause, $filterParams, $filterTypes);
                }

                $added_by = $filter_rows;
                $user_ids = [];

                if (count($added_by) > 0) {
                    foreach ($added_by as $i) {
                        $user_ids[] = is_numeric($i['added_by_id']) ? (int) $i['added_by_id'] : 0;
                    }

                    $username_of = $this->userRepository->findUsernamesByIds(
                        Config::userFields()['id'],
                        Config::userFields()['username'],
                        Tables::users(),
                        $user_ids,
                    );

                    foreach (array_keys($added_by) as $added_by_idx) {
                        $added_by_id_raw = $added_by[$added_by_idx]['added_by_id'];
                        $added_by_id = is_numeric($added_by_id_raw) ? (int) $added_by_id_raw : 0;
                        $added_by[$added_by_idx]['added_by_name'] = $username_of[(string) $added_by_id] ?? 'user #' . $added_by_id . ' (deleted)';
                    }
                }

                $template->assign('ADDED_BY', $added_by);

                $added_by_field_raw = is_array($my_search['fields']['added_by']) ? $my_search['fields']['added_by'] : [];
                $added_by_field_int = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $added_by_field_raw);
                $my_search['fields']['added_by'] = array_intersect($added_by_field_int, $user_ids);
            } elseif (isset($my_search['fields']['added_by'])) {
                unset($my_search['fields']['added_by']);
            }

            if (isset($my_search['fields']['cat']) and $display_filters['album']['access']) {
                $cat_field = is_array($my_search['fields']['cat']) ? $my_search['fields']['cat'] : [];
                $cat_words = is_array($cat_field['words'] ?? null) ? $cat_field['words'] : [];
                if (count($cat_words) > 0) {
                    $fullname_of = [];

                    $catWordsInt = array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $cat_words));
                    foreach ($this->categoryRepository->findIdUppercatsForVisibleIds((int) $userId, $catWordsInt) as $row) {
                        $uppercats_val = $row['uppercats'];
                        $cat_display_name = $this->htmlService->getCatDisplayNameCache(
                            is_scalar($uppercats_val) ? (string) $uppercats_val : '',
                            ''
                        );
                        $row['fullname'] = strip_tags($cat_display_name);
                        $sfRowIdRaw = $row['id'] ?? null;
                        $fullname_of[is_string($sfRowIdRaw) ? $sfRowIdRaw : ''] = $row['fullname'];
                    }

                    $template->assign('fullname_of', json_encode($fullname_of));

                    $cat_words_str = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $cat_words);
                    $cat_field['words'] = array_intersect($cat_words_str, array_keys($fullname_of));
                    $my_search['fields']['cat'] = $cat_field;
                }
            } elseif (isset($my_search['fields']['cat'])) {
                unset($my_search['fields']['cat']);
            }

            if (isset($my_search['fields']['filetypes']) and $display_filters['file_type']['access']) {
                [$filter_clause, $filterParams, $filterTypes] = $this->searchService->getClauseForFilter('filetypes');

                $cache_key    = md5('file_exts' . $userId . $userCacheTime . AppInfo::VERSION);
                $item_fe      = $this->pool->getItem($cache_key);
                if ($item_fe->isHit()) {
                    $all_exts_raw = $item_fe->get();
                    $all_exts     = is_array($all_exts_raw) ? $all_exts_raw : [];
                } else {
                    $all_exts = $this->searchRepository->findAllFileExtensions($forbidden[0], $forbidden[1], $forbidden[2]);
                    $item_fe->set($all_exts);
                    $item_fe->expiresAfter(86400);
                    $this->pool->save($item_fe);
                }

                if (preg_match('/^image_id IN/', $filter_clause)) {
                    $filtered_exts = $this->searchRepository->findFilteredFileExtensions($filter_clause, $filterParams, $filterTypes);

                    $exts = [];
                    foreach ($all_exts as $ext => $counter) {
                        $exts[$ext] = $filtered_exts[$ext] ?? 0;
                    }
                    $template->assign('FILETYPES', $exts);
                } else {
                    $template->assign('FILETYPES', $all_exts);
                }
            } elseif (isset($my_search['fields']['filetypes'])) {
                unset($my_search['fields']['filetypes']);
            }

            if (Config::rateEnabled()) {
                $template->assign('SHOW_FILTER_RATINGS', true);

                if (isset($my_search['fields']['ratings']) and $display_filters['rating']['access']) {
                    [$filter_clause, $filterParams, $filterTypes] = $this->searchService->getClauseForFilter('ratings');
                    $cache_key         = md5('filter_ratings' . $userId . $userCacheTime . AppInfo::VERSION);
                    $item_rat          = $this->pool->getItem($cache_key);
                    $cache_hit_ratings = !preg_match('/^image_id IN/', $filter_clause) && $item_rat->isHit();
                    if ($cache_hit_ratings) {
                        $ratings_raw = $item_rat->get();
                    } else {
                        $ratings_raw = null;
                    }
                    $ratings      = is_array($ratings_raw) ? $ratings_raw : null;
                    $set_cache_rat = !preg_match('/^image_id IN/', $filter_clause) && !$cache_hit_ratings;

                    if (!$cache_hit_ratings) {
                        $filter_rows = $this->searchRepository->findRatingsForFilter($filter_clause, $filterParams, $filterTypes);
                        $ratings = array_fill(0, 6, 0);

                        foreach ($filter_rows as $row) {
                            $r = 5;
                            if (!isset($row['rating_score'])) {
                                $r = 0;
                            } else {
                                for ($i = 1; $i <= 4; $i++) {
                                    if ($row['rating_score'] < $i) {
                                        $r = $i;
                                        break;
                                    }
                                }
                            }
                            $ratings[$r]++;
                        }

                        if ($set_cache_rat) {
                            $item_rat->set($ratings);
                            $item_rat->expiresAfter(86400);
                            $this->pool->save($item_rat);
                        }
                    }
                    $template->assign('RATING', $ratings);
                } elseif (isset($my_search['fields']['ratings'])) {
                    unset($my_search['fields']['ratings']);
                }
            } else {
                $template->assign('SHOW_FILTER_RATINGS', false);
                if (isset($my_search['fields']['ratings'])) {
                    unset($my_search['fields']['ratings']);
                }
            }

            if (isset($my_search['fields']['filesize_min']) && isset($my_search['fields']['filesize_max']) and $display_filters['file_size']['access']) {
                [$filter_clause, $filterParams, $filterTypes] = $this->searchService->getClauseForFilter('filesize');
                $filesizes = [];
                $filesize = [];

                foreach ($this->searchRepository->findFilesizesForFilter($filter_clause, $filterParams, $filterTypes) as $row) {
                    $fs_val = is_numeric($row['filesize']) ? (float) $row['filesize'] : 0.0;
                    $key_fs = sprintf('%.1f', $fs_val / 1024.0);
                    $filesizes[$key_fs] = ($filesizes[$key_fs] ?? 0) + 1;
                }

                if (empty($filesizes)) {
                    $filesizes = [0, 1, 2, 5, 8, 15];
                }

                $unique_filesizes = array_keys($filesizes);
                sort($unique_filesizes, SORT_NUMERIC);

                $filesize['list'] = implode(',', $unique_filesizes);
                $filesize['bounds'] = ['min' => $unique_filesizes[0], 'max' => end($unique_filesizes)];

                $fs_min_raw = $my_search['fields']['filesize_min'];
                $fs_max_raw = $my_search['fields']['filesize_max'];
                $filesize['selected'] = [
                    'min' => !empty($fs_min_raw) ? sprintf('%.1f', (is_numeric($fs_min_raw) ? (float) $fs_min_raw : 0.0) / 1024.0) : $unique_filesizes[0],
                    'max' => !empty($fs_max_raw) ? sprintf('%.1f', (is_numeric($fs_max_raw) ? (float) $fs_max_raw : 0.0) / 1024.0) : end($unique_filesizes),
                ];

                $template->assign('FILESIZE', $filesize);
            } elseif (isset($my_search['fields']['filesize_min']) && isset($my_search['fields']['filesize_max'])) {
                unset($my_search['fields']['filesize_min']);
                unset($my_search['fields']['filesize_max']);
            }

            if (isset($my_search['fields']['ratios']) and $display_filters['ratio']['access']) {
                [$filter_clause, $filterParams, $filterTypes] = $this->searchService->getClauseForFilter('ratios');
                $cache_key         = md5('filter_ratios' . $userId . $userCacheTime . AppInfo::VERSION);
                $item_ratio        = $this->pool->getItem($cache_key);
                $cache_hit_ratios  = !preg_match('/^image_id IN/', $filter_clause) && $item_ratio->isHit();
                if ($cache_hit_ratios) {
                    $ratios_raw = $item_ratio->get();
                } else {
                    $ratios_raw = null;
                }
                $ratios         = is_array($ratios_raw) ? $ratios_raw : null;
                $set_cache_ratio = !preg_match('/^image_id IN/', $filter_clause) && !$cache_hit_ratios;

                if (!$cache_hit_ratios) {
                    $filter_rows = $this->searchRepository->findRatiosForFilter($filter_clause, $filterParams, $filterTypes);
                    $ratios = ['Portrait' => 0, 'square' => 0, 'Landscape' => 0, 'Panorama' => 0];

                    foreach ($filter_rows as $row) {
                        $row_width = is_numeric($row['width']) ? (float) $row['width'] : 0.0;
                        $row_height = is_numeric($row['height']) ? (float) $row['height'] : 0.0;
                        if ($row_width <= 0 and $row_height <= 0) {
                            continue;
                        }
                        $r = $row_height > 0 ? $row_width / $row_height : 0.0;
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

                    if ($set_cache_ratio) {
                        $item_ratio->set($ratios);
                        $item_ratio->expiresAfter(86400);
                        $this->pool->save($item_ratio);
                    }
                }
                $template->assign('RATIOS', $ratios);
            } elseif (isset($my_search['fields']['ratios'])) {
                unset($my_search['fields']['ratios']);
            }

            if (isset($my_search['fields']['height_min']) and isset($my_search['fields']['height_max']) and $display_filters['height']['access']) {
                [$filter_clause, $filterParams, $filterTypes] = $this->searchService->getClauseForFilter('height');

                if (!preg_match('/^image_id IN/', $filter_clause)) {
                    $cache_key = md5('filter_height_rows' . $userId . $userCacheTime . AppInfo::VERSION);
                    $item_h    = $this->pool->getItem($cache_key);
                    if ($item_h->isHit()) {
                        $filter_rows_raw = $item_h->get();
                        $heights         = is_array($filter_rows_raw)
                            ? array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $filter_rows_raw))
                            : [];
                    } else {
                        $heights = $this->searchRepository->findDistinctHeightsForFilter($filter_clause, $filterParams, $filterTypes);
                        $item_h->set($heights);
                        $item_h->expiresAfter(86400);
                        $this->pool->save($item_h);
                    }
                } else {
                    $heights = $this->searchRepository->findDistinctHeightsForFilter($filter_clause, $filterParams, $filterTypes);
                }

                $height = [
                    'list' => implode(',', array_map(static fn (int $v): string => (string) $v, $heights)),
                    'bounds' => ['min' => $heights[0] ?? 0, 'max' => (int) end($heights)],
                    'selected' => [
                        'min' => !empty($my_search['fields']['height_min']) ? $my_search['fields']['height_min'] : ($heights[0] ?? 0),
                        'max' => !empty($my_search['fields']['height_max']) ? $my_search['fields']['height_max'] : (int) end($heights),
                    ],
                ];
                $template->assign('HEIGHT', $height);
            } elseif (isset($my_search['fields']['height_min']) && isset($my_search['fields']['height_max'])) {
                unset($my_search['fields']['height_min']);
                unset($my_search['fields']['height_max']);
            }

            if (isset($my_search['fields']['width_min']) and isset($my_search['fields']['width_max']) and $display_filters['width']['access']) {
                [$filter_clause, $filterParams, $filterTypes] = $this->searchService->getClauseForFilter('width');

                if (!preg_match('/^image_id IN/', $filter_clause)) {
                    $cache_key = md5('filter_width_rows' . $userId . $userCacheTime . AppInfo::VERSION);
                    $item_w    = $this->pool->getItem($cache_key);
                    if ($item_w->isHit()) {
                        $filter_rows_raw = $item_w->get();
                        $widths          = is_array($filter_rows_raw)
                            ? array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $filter_rows_raw))
                            : [];
                    } else {
                        $widths = $this->searchRepository->findDistinctWidthsForFilter($filter_clause, $filterParams, $filterTypes);
                        $item_w->set($widths);
                        $item_w->expiresAfter(86400);
                        $this->pool->save($item_w);
                    }
                } else {
                    $widths = $this->searchRepository->findDistinctWidthsForFilter($filter_clause, $filterParams, $filterTypes);
                }

                $width = [
                    'list' => implode(',', array_map(static fn (int $v): string => (string) $v, $widths)),
                    'bounds' => ['min' => $widths[0] ?? 0, 'max' => (int) end($widths)],
                    'selected' => [
                        'min' => !empty($my_search['fields']['width_min']) ? $my_search['fields']['width_min'] : ($widths[0] ?? 0),
                        'max' => !empty($my_search['fields']['width_max']) ? $my_search['fields']['width_max'] : (int) end($widths),
                    ],
                ];
                $template->assign('WIDTH', $width);
            } elseif (isset($my_search['fields']['width_min']) && isset($my_search['fields']['width_max'])) {
                unset($my_search['fields']['width_min']);
                unset($my_search['fields']['width_max']);
            }

            $template->assign(['GP' => json_encode($my_search), 'SEARCH_ID' => $searchRaw]);

            $sliders_data = [];
            if (isset($filesize)) {
                $sliders_data['filesizes'] = [
                    'values' => array_map(floatval(...), explode(',', (string) $filesize['list'])),
                    'selected' => ['min' => $filesize['selected']['min'], 'max' => $filesize['selected']['max']],
                    'text' => Lang::t('between %s and %s MB'),
                ];
            }
            if (isset($height)) {
                $sliders_data['heights'] = [
                    'values' => array_map(intval(...), explode(',', (string) $height['list'])),
                    'selected' => ['min' => $height['selected']['min'], 'max' => $height['selected']['max']],
                    'text' => Lang::t('between %d and %d pixels'),
                ];
            }
            if (isset($width)) {
                $sliders_data['widths'] = [
                    'values' => array_map(intval(...), explode(',', (string) $width['list'])),
                    'selected' => ['min' => $width['selected']['min'], 'max' => $width['selected']['max']],
                    'text' => Lang::t('between %d and %d pixels'),
                ];
            }

            $template->assign('page_data_json', json_encode(
                [
                    'global_params' => $my_search,
                    'search_id' => $searchRaw,
                    'fullname_of_cat' => $fullname_of ?? [],
                    'show_filter_ratings' => Config::rateEnabled() ? true : false,
                    'sliders' => $sliders_data,
                    'str_word_widget_label' => Lang::t('Search for words'),
                    'str_tags_widget_label' => Lang::t('Tag'),
                    'str_album_widget_label' => Lang::t('Album'),
                    'str_author_widget_label' => Lang::t('Author'),
                    'str_added_by_widget_label' => Lang::t('Added by'),
                    'str_filetypes_widget_label' => Lang::t('File type'),
                    'str_ratio_widget_label' => Lang::t('Ratio'),
                    'str_rating_widget_label' => Lang::t('Rating'),
                    'str_no_rating' => Lang::t('no rate'),
                    'str_between_rating' => Lang::t('between %d and %d'),
                    'str_filesize_widget_label' => Lang::t('Filesize'),
                    'str_width_widget_label' => Lang::t('Width'),
                    'str_height_widget_label' => Lang::t('Height'),
                    'str_expert_widget_label' => Lang::t('Expert mode'),
                    'str_empty_search_top_alt' => Lang::t('Fill in the filters to start a search'),
                    'str_empty_search_bot_alt' => Lang::t('Pre-established filters are proposed, but you can add or remove them using the "Choose filters" button.'),
                    'str_search_in_ab' => Lang::t('Search in albums'),
                    'str_ratios_label' => [
                        'Portrait' => Lang::t('Portrait'),
                        'square' => Lang::t('square'),
                        'Landscape' => Lang::t('Landscape'),
                        'Panorama' => Lang::t('Panorama'),
                    ],
                ],
                JSON_HEX_TAG | JSON_UNESCAPED_UNICODE
            ));

            if (0 == $ctx->start and $ctx->chronologyField === '') {
                $matchingCatIdsRaw = $search_details['matching_cat_ids'] ?? null;
                $matchingCatIds = is_array($matchingCatIdsRaw) ? $matchingCatIdsRaw : null;
                if ($matchingCatIds !== null) {
                    $cat_ids_int = array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $matchingCatIds));
                    if (count($cat_ids_int)) {
                        $cats = $this->categoryRepository->findAllColumnsForVisibleIds((int) $userId, $cat_ids_int);
                        usort($cats, fn (array $a, array $b): int => $this->htmlService->nameCompare($a, $b));
                        $albums_found = [];
                        foreach ($cats as $cat) {
                            $single_link = false;
                            $albums_found[] = $this->htmlService->getCatDisplayNameCache(
                                is_scalar($cat['uppercats'] ?? null) ? (string) $cat['uppercats'] : '',
                                '',
                                $single_link
                            );
                        }

                        if (count($albums_found) > 0) {
                            $template->assign('ALBUMS_FOUND', $albums_found);
                        }
                    }
                }
                $matchingTagIdsRaw = $search_details['matching_tag_ids'] ?? null;
                $matchingTagIds = is_array($matchingTagIdsRaw) ? $matchingTagIdsRaw : null;
                if ($matchingTagIds !== null) {
                    $tag_ids = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $matchingTagIds);

                    if (count($tag_ids) > 0) {
                        $tags = $this->tagService->getAvailableTags($tag_ids);
                        usort($tags, fn (mixed $a, mixed $b): int => $this->htmlService->tagAlphaCompare(is_array($a) ? $a : [], is_array($b) ? $b : []));
                        $tags_found = [];
                        foreach ($tags as $tag) {
                            if (!is_array($tag)) {
                                continue;
                            }
                            $url = $this->urlService->makeIndexUrl(['tags' => [$tag]]);
                            $tags_found[] = sprintf('<a href="%s">%s</a>', $url, is_scalar($tag['name'] ?? null) ? (string) $tag['name'] : '');
                        }

                        if (count($tags_found) > 0) {
                            $template->assign('TAGS_FOUND', $tags_found);
                        }
                    }
                }
            }
        }
    }

    /**
     * Normalize a mixed cache value into a typed date-filter data structure.
     *
     * @return array{pre_counters: array<string, int>, list_of_dates: array<mixed>}
     *
     * @param array<mixed>|null $raw
     */
    private function normalizeDateData(array|null $raw): array
    {
        $rawArr = is_array($raw) ? $raw : [];
        $pre = [];
        if (is_array($rawArr['pre_counters'] ?? null)) {
            foreach ($rawArr['pre_counters'] as $k => $v) {
                $pre[(string) $k] = is_numeric($v) ? (int) $v : 0;
            }
        }
        $dates = is_array($rawArr['list_of_dates'] ?? null) ? $rawArr['list_of_dates'] : [];
        return ['pre_counters' => $pre, 'list_of_dates' => $dates];
    }

    /**
     * Normalize a mixed cache value to a flat array of rows.
     *
     * @return array<int, array<mixed>>
     *
     * @param array<mixed>|null $raw
     */
    private function normalizeRows(array|null $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            $out[] = is_array($row) ? $row : [];
        }
        return $out;
    }
}
