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

        if (Section::Search !== $ctx->section || $ctx->searchDetails === []) {
            return;
        }

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
        $search_details['forbidden'] = $forbidden->where;
        $this->searchService->setForbidden($forbidden);

        $renderCtx = new FilterRenderContext(
            mySearch: $my_search,
            displayFilters: $display_filters,
            userId: $userId,
            userCacheTime: $userCacheTime,
            forbidden: $forbidden,
            rules: $rules,
            template: $template,
            searchRaw: $searchRaw,
        );

        $this->renderAllwordsFilter($renderCtx);
        $this->renderTagsFilter($renderCtx);
        $this->renderExpertFilter($renderCtx);
        $this->renderAuthorFilter($renderCtx);
        $this->renderDatePostedFilter($renderCtx);
        $this->renderDateCreatedFilter($renderCtx);
        $this->renderAddedByFilter($renderCtx);
        $this->renderCategoryFilter($renderCtx);
        $this->renderFiletypeFilter($renderCtx);
        $this->renderRatingFilter($renderCtx);
        $this->renderFilesizeFilter($renderCtx);
        $this->renderRatioFilter($renderCtx);
        $this->renderHeightFilter($renderCtx);
        $this->renderWidthFilter($renderCtx);

        $template->assign(['GP' => json_encode($renderCtx->mySearch), 'SEARCH_ID' => $searchRaw]);

        $sliders_data = [];
        if ($renderCtx->filesize !== null) {
            $sliders_data['filesizes'] = [
                'values' => array_map(floatval(...), explode(',', $renderCtx->filesize['list'])),
                'selected' => ['min' => $renderCtx->filesize['selected']['min'], 'max' => $renderCtx->filesize['selected']['max']],
                'text' => Lang::t('between %s and %s MB'),
            ];
        }
        if ($renderCtx->height !== null) {
            $sliders_data['heights'] = [
                'values' => array_map(intval(...), explode(',', $renderCtx->height['list'])),
                'selected' => ['min' => $renderCtx->height['selected']['min'], 'max' => $renderCtx->height['selected']['max']],
                'text' => Lang::t('between %d and %d pixels'),
            ];
        }
        if ($renderCtx->width !== null) {
            $sliders_data['widths'] = [
                'values' => array_map(intval(...), explode(',', $renderCtx->width['list'])),
                'selected' => ['min' => $renderCtx->width['selected']['min'], 'max' => $renderCtx->width['selected']['max']],
                'text' => Lang::t('between %d and %d pixels'),
            ];
        }

        $template->assign('page_data_json', json_encode(
            [
                'global_params' => $renderCtx->mySearch,
                'search_id' => $searchRaw,
                'fullname_of_cat' => $renderCtx->fullnameOf ?? [],
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

        if (0 != $ctx->start || $ctx->chronologyField !== '') {
            return;
        }

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
        if ($matchingTagIds === null) {
            return;
        }
        $tag_ids = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $matchingTagIds);
        if (count($tag_ids) === 0) {
            return;
        }

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

    private function renderAllwordsFilter(FilterRenderContext $ctx): void
    {
        if (isset($ctx->mySearch['fields']['allwords']) and !$ctx->displayFilters['words']['access']) {
            unset($ctx->mySearch['fields']['allwords']);
        }
    }

    private function renderTagsFilter(FilterRenderContext $ctx): void
    {
        if ($ctx->rules->tags === null) {
            return;
        }
        if (!$ctx->displayFilters['tags']['access']) {
            unset($ctx->mySearch['fields']['tags']);
            return;
        }

        $other_filters_items = $this->searchService->getItemsForFilter('tags');
        if (false === $other_filters_items) {
            $filter_tags = $this->tagService->getAvailableTags();
            usort($filter_tags, fn (mixed $a, mixed $b): int => $this->htmlService->tagAlphaCompare(is_array($a) ? $a : [], is_array($b) ? $b : []));
        } else {
            $filter_tags = $this->tagService->getCommonTags($other_filters_items, 0);
            $selected_tag_ids_str = array_map(static fn (int $v): string => (string) $v, $ctx->rules->tags->tagIds);
            $available_ids_str    = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column($filter_tags, 'id'));
            $missing_tag_ids      = array_diff($selected_tag_ids_str, $available_ids_str);

            if (count($missing_tag_ids) > 0) {
                $filter_tags = array_merge($this->tagService->getAvailableTags(array_map(intval(...), $missing_tag_ids)), $filter_tags);
            }
        }

        $ctx->template->assign('TAGS', $filter_tags);

        $filter_tag_ids     = count($filter_tags) > 0 ? array_column($filter_tags, 'id') : [];
        $filter_tag_ids_str = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $filter_tag_ids);
        $selected_str       = array_map(static fn (int $v): string => (string) $v, $ctx->rules->tags->tagIds);
        $ctx->mySearch['fields']['tags'] = [
            'words' => array_values(array_intersect($selected_str, $filter_tag_ids_str)),
            'mode'  => $ctx->rules->tags->mode->value,
        ];
    }

    private function renderExpertFilter(FilterRenderContext $ctx): void
    {
        if (!isset($ctx->mySearch['fields']['expert'])) {
            return;
        }
        if (!$ctx->displayFilters['expert']['access']) {
            unset($ctx->mySearch['fields']['expert']);
            return;
        }
        $this->langService->loadLanguage('help_quick_search.lang');
    }

    private function renderAuthorFilter(FilterRenderContext $ctx): void
    {
        if (!isset($ctx->mySearch['fields']['author']) || !$ctx->displayFilters['author']['access']) {
            if (isset($ctx->mySearch['fields']['author'])) {
                unset($ctx->mySearch['fields']['author']);
            }
            return;
        }

        $filter = $this->searchService->getClauseForFilter('author');

        if (!preg_match('/^image_id IN/', $filter->where)) {
            $cache_key = md5('filter_author_rows' . $ctx->userId . $ctx->userCacheTime . AppInfo::VERSION);
            $item      = $this->pool->getItem($cache_key);
            if ($item->isHit()) {
                $filter_rows_raw = $item->get();
                $filter_rows     = $this->normalizeRows(is_array($filter_rows_raw) ? $filter_rows_raw : null);
            } else {
                $db_rows = $this->searchRepository->findAuthorsForFilter($filter->where, $filter->params, $filter->types);
                $item->set($db_rows);
                $item->expiresAfter(86400);
                $this->pool->save($item);
                $filter_rows = $this->normalizeRows($db_rows);
            }
        } else {
            $filter_rows = $this->searchRepository->findAuthorsForFilter($filter->where, $filter->params, $filter->types);
        }

        $author_names = [];
        foreach ($filter_rows as $author) {
            $author_names[] = $author['author'] ?? '';
        }
        $ctx->template->assign('AUTHORS', $filter_rows);

        $author_words_raw = $ctx->rules->author === null ? [] : $ctx->rules->author->words;
        $author_names_str = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $author_names);
        $ctx->mySearch['fields']['author'] = [
            'words' => array_values(array_intersect($author_words_raw, $author_names_str)),
        ];
    }

    private function renderDatePostedFilter(FilterRenderContext $ctx): void
    {
        if (!isset($ctx->mySearch['fields']['date_posted']) || !$ctx->displayFilters['post_date']['access']) {
            if (isset($ctx->mySearch['fields']['date_posted'])) {
                unset($ctx->mySearch['fields']['date_posted']);
            }
            return;
        }

        $filter = $this->searchService->getClauseForFilter('date_posted');
        $cache_key     = md5('filter_date_posted' . $ctx->userId . $ctx->userCacheTime . AppInfo::VERSION);
        $item_dp       = $this->pool->getItem($cache_key);
        $cache_hit_date_posted = !preg_match('/^image_id IN/', $filter->where) && $item_dp->isHit();
        if ($cache_hit_date_posted) {
            $date_posted_raw = $item_dp->get();
        } else {
            $date_posted_raw = ['pre_counters' => [], 'list_of_dates' => []];
        }
        $date_posted  = $this->normalizeDateData(is_array($date_posted_raw) ? $date_posted_raw : null);
        $set_cache_dp = !preg_match('/^image_id IN/', $filter->where) && !$cache_hit_date_posted;

        if (!$cache_hit_date_posted) {
            $thresholds = $this->searchRepository->findDatePostedThresholds();

            $list_of_dates = [];
            $pre_counters = [];

            foreach ($this->searchRepository->findImageDatePostedRows($filter->where, $filter->params, $filter->types) as $row) {
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

        $ctx->template->assign('LIST_DATE_POSTED', $dp_list);
        $ctx->template->assign('DATE_POSTED', $counters);
    }

    private function renderDateCreatedFilter(FilterRenderContext $ctx): void
    {
        if (!isset($ctx->mySearch['fields']['date_created']) || !$ctx->displayFilters['creation_date']['access']) {
            if (isset($ctx->mySearch['fields']['date_created'])) {
                unset($ctx->mySearch['fields']['date_created']);
            }
            return;
        }

        $filter = $this->searchService->getClauseForFilter('date_created');
        $cache_key     = md5('filter_date_created' . $ctx->userId . $ctx->userCacheTime . AppInfo::VERSION);
        $item_dc       = $this->pool->getItem($cache_key);
        $cache_hit_date_created = !preg_match('/^image_id IN/', $filter->where) && $item_dc->isHit();
        if ($cache_hit_date_created) {
            $date_created_raw = $item_dc->get();
        } else {
            $date_created_raw = ['pre_counters' => [], 'list_of_dates' => []];
        }
        $date_created  = $this->normalizeDateData(is_array($date_created_raw) ? $date_created_raw : null);
        $set_cache_dc  = !preg_match('/^image_id IN/', $filter->where) && !$cache_hit_date_created;

        if (!$cache_hit_date_created) {
            $thresholds = $this->searchRepository->findDateCreatedThresholds();

            $list_of_dates = [];
            $pre_counters = [];

            foreach ($this->searchRepository->findImageDateCreatedRows($filter->where, $filter->params, $filter->types) as $row) {
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

        $ctx->template->assign('LIST_DATE_CREATED', $dc_list);
        $ctx->template->assign('DATE_CREATED', $counters);
    }

    private function renderAddedByFilter(FilterRenderContext $ctx): void
    {
        if (!isset($ctx->mySearch['fields']['added_by']) || !$ctx->displayFilters['added_by']['access']) {
            if (isset($ctx->mySearch['fields']['added_by'])) {
                unset($ctx->mySearch['fields']['added_by']);
            }
            return;
        }

        $filter = $this->searchService->getClauseForFilter('added_by');

        if (!preg_match('/^image_id IN/', $filter->where)) {
            $cache_key = md5('filter_added_by_rows' . $ctx->userId . $ctx->userCacheTime . AppInfo::VERSION);
            $item_ab   = $this->pool->getItem($cache_key);
            if ($item_ab->isHit()) {
                $filter_rows_raw = $item_ab->get();
                $filter_rows     = $this->normalizeRows(is_array($filter_rows_raw) ? $filter_rows_raw : null);
            } else {
                $db_rows = $this->searchRepository->findAddedByForFilter($filter->where, $filter->params, $filter->types);
                $item_ab->set($db_rows);
                $item_ab->expiresAfter(86400);
                $this->pool->save($item_ab);
                $filter_rows = $this->normalizeRows($db_rows);
            }
        } else {
            $filter_rows = $this->searchRepository->findAddedByForFilter($filter->where, $filter->params, $filter->types);
        }

        $added_by = $filter_rows;
        $user_ids = [];

        if (count($added_by) > 0) {
            foreach ($added_by as $i) {
                $user_ids[] = is_numeric($i['added_by_id']) ? (int) $i['added_by_id'] : 0;
            }

            $username_of = $this->userRepository->findUsernamesByIds(
                Config::userFields()->id,
                Config::userFields()->username,
                Tables::users(),
                $user_ids,
            );

            foreach (array_keys($added_by) as $added_by_idx) {
                $added_by_id_raw = $added_by[$added_by_idx]['added_by_id'];
                $added_by_id = is_numeric($added_by_id_raw) ? (int) $added_by_id_raw : 0;
                $added_by[$added_by_idx]['added_by_name'] = $username_of[(string) $added_by_id] ?? 'user #' . $added_by_id . ' (deleted)';
            }
        }

        $ctx->template->assign('ADDED_BY', $added_by);

        $added_by_field_raw = is_array($ctx->mySearch['fields']['added_by']) ? $ctx->mySearch['fields']['added_by'] : [];
        $added_by_field_int = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $added_by_field_raw);
        $ctx->mySearch['fields']['added_by'] = array_intersect($added_by_field_int, $user_ids);
    }

    private function renderCategoryFilter(FilterRenderContext $ctx): void
    {
        if (!isset($ctx->mySearch['fields']['cat']) || !$ctx->displayFilters['album']['access']) {
            if (isset($ctx->mySearch['fields']['cat'])) {
                unset($ctx->mySearch['fields']['cat']);
            }
            return;
        }

        $cat_field = is_array($ctx->mySearch['fields']['cat']) ? $ctx->mySearch['fields']['cat'] : [];
        $cat_words = is_array($cat_field['words'] ?? null) ? $cat_field['words'] : [];
        if (count($cat_words) === 0) {
            return;
        }

        $fullname_of = [];
        $catWordsInt = array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $cat_words));
        foreach ($this->categoryRepository->findIdUppercatsForVisibleIds((int) $ctx->userId, $catWordsInt) as $row) {
            $uppercats_val = $row['uppercats'];
            $cat_display_name = $this->htmlService->getCatDisplayNameCache(
                is_scalar($uppercats_val) ? (string) $uppercats_val : '',
                ''
            );
            $row['fullname'] = strip_tags($cat_display_name);
            $sfRowIdRaw = $row['id'] ?? null;
            $fullname_of[is_string($sfRowIdRaw) ? $sfRowIdRaw : ''] = $row['fullname'];
        }

        $ctx->template->assign('fullname_of', json_encode($fullname_of));
        $ctx->fullnameOf = $fullname_of;

        $cat_words_str = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $cat_words);
        $cat_field['words'] = array_intersect($cat_words_str, array_keys($fullname_of));
        $ctx->mySearch['fields']['cat'] = $cat_field;
    }

    private function renderFiletypeFilter(FilterRenderContext $ctx): void
    {
        if (!isset($ctx->mySearch['fields']['filetypes']) || !$ctx->displayFilters['file_type']['access']) {
            if (isset($ctx->mySearch['fields']['filetypes'])) {
                unset($ctx->mySearch['fields']['filetypes']);
            }
            return;
        }

        $filter = $this->searchService->getClauseForFilter('filetypes');

        $cache_key    = md5('file_exts' . $ctx->userId . $ctx->userCacheTime . AppInfo::VERSION);
        $item_fe      = $this->pool->getItem($cache_key);
        if ($item_fe->isHit()) {
            $all_exts_raw = $item_fe->get();
            $all_exts     = is_array($all_exts_raw) ? $all_exts_raw : [];
        } else {
            $all_exts = $this->searchRepository->findAllFileExtensions($ctx->forbidden->where, $ctx->forbidden->params, $ctx->forbidden->types);
            $item_fe->set($all_exts);
            $item_fe->expiresAfter(86400);
            $this->pool->save($item_fe);
        }

        if (preg_match('/^image_id IN/', $filter->where)) {
            $filtered_exts = $this->searchRepository->findFilteredFileExtensions($filter->where, $filter->params, $filter->types);

            $exts = [];
            foreach ($all_exts as $ext => $counter) {
                $exts[$ext] = $filtered_exts[$ext] ?? 0;
            }
            $ctx->template->assign('FILETYPES', $exts);
        } else {
            $ctx->template->assign('FILETYPES', $all_exts);
        }
    }

    private function renderRatingFilter(FilterRenderContext $ctx): void
    {
        $rateEnabled = Config::rateEnabled();
        $ctx->template->assign('SHOW_FILTER_RATINGS', $rateEnabled);

        if (!$rateEnabled || !isset($ctx->mySearch['fields']['ratings']) || !$ctx->displayFilters['rating']['access']) {
            if (isset($ctx->mySearch['fields']['ratings'])) {
                unset($ctx->mySearch['fields']['ratings']);
            }
            return;
        }

        $filter            = $this->searchService->getClauseForFilter('ratings');
        $cache_key         = md5('filter_ratings' . $ctx->userId . $ctx->userCacheTime . AppInfo::VERSION);
        $item_rat          = $this->pool->getItem($cache_key);
        $cache_hit_ratings = !preg_match('/^image_id IN/', $filter->where) && $item_rat->isHit();
        $ratings_raw       = $cache_hit_ratings ? $item_rat->get() : null;
        $ratings           = is_array($ratings_raw) ? $ratings_raw : null;
        $set_cache_rat     = !preg_match('/^image_id IN/', $filter->where) && !$cache_hit_ratings;

        if (!$cache_hit_ratings) {
            $filter_rows = $this->searchRepository->findRatingsForFilter($filter->where, $filter->params, $filter->types);
            $ratings     = array_fill(0, 6, 0);

            foreach ($filter_rows as $row) {
                if (!isset($row['rating_score'])) {
                    $ratings[0]++;
                    continue;
                }
                $r = 5;
                for ($i = 1; $i <= 4; $i++) {
                    if ($row['rating_score'] < $i) {
                        $r = $i;
                        break;
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
        $ctx->template->assign('RATING', $ratings);
    }

    private function renderFilesizeFilter(FilterRenderContext $ctx): void
    {
        if (!isset($ctx->mySearch['fields']['filesize_min']) || !isset($ctx->mySearch['fields']['filesize_max']) || !$ctx->displayFilters['file_size']['access']) {
            if (isset($ctx->mySearch['fields']['filesize_min']) && isset($ctx->mySearch['fields']['filesize_max'])) {
                unset($ctx->mySearch['fields']['filesize_min']);
                unset($ctx->mySearch['fields']['filesize_max']);
            }
            return;
        }

        $filter = $this->searchService->getClauseForFilter('filesize');
        $filesizes = [];

        foreach ($this->searchRepository->findFilesizesForFilter($filter->where, $filter->params, $filter->types) as $row) {
            $fs_val = is_numeric($row['filesize']) ? (float) $row['filesize'] : 0.0;
            $key_fs = sprintf('%.1f', $fs_val / 1024.0);
            $filesizes[$key_fs] = ($filesizes[$key_fs] ?? 0) + 1;
        }

        if (empty($filesizes)) {
            $filesizes = [0, 1, 2, 5, 8, 15];
        }

        $unique_filesizes = array_keys($filesizes);
        sort($unique_filesizes, SORT_NUMERIC);

        $fs_min_raw = $ctx->mySearch['fields']['filesize_min'];
        $fs_max_raw = $ctx->mySearch['fields']['filesize_max'];
        $filesize = [
            'list' => implode(',', $unique_filesizes),
            'bounds' => ['min' => $unique_filesizes[0], 'max' => end($unique_filesizes)],
            'selected' => [
                'min' => !empty($fs_min_raw) ? sprintf('%.1f', (is_numeric($fs_min_raw) ? (float) $fs_min_raw : 0.0) / 1024.0) : $unique_filesizes[0],
                'max' => !empty($fs_max_raw) ? sprintf('%.1f', (is_numeric($fs_max_raw) ? (float) $fs_max_raw : 0.0) / 1024.0) : end($unique_filesizes),
            ],
        ];

        $ctx->template->assign('FILESIZE', $filesize);
        $ctx->filesize = $filesize;
    }

    private function renderRatioFilter(FilterRenderContext $ctx): void
    {
        if (!isset($ctx->mySearch['fields']['ratios']) || !$ctx->displayFilters['ratio']['access']) {
            if (isset($ctx->mySearch['fields']['ratios'])) {
                unset($ctx->mySearch['fields']['ratios']);
            }
            return;
        }

        $filter = $this->searchService->getClauseForFilter('ratios');
        $cache_key         = md5('filter_ratios' . $ctx->userId . $ctx->userCacheTime . AppInfo::VERSION);
        $item_ratio        = $this->pool->getItem($cache_key);
        $cache_hit_ratios  = !preg_match('/^image_id IN/', $filter->where) && $item_ratio->isHit();
        if ($cache_hit_ratios) {
            $ratios_raw = $item_ratio->get();
        } else {
            $ratios_raw = null;
        }
        $ratios         = is_array($ratios_raw) ? $ratios_raw : null;
        $set_cache_ratio = !preg_match('/^image_id IN/', $filter->where) && !$cache_hit_ratios;

        if (!$cache_hit_ratios) {
            $filter_rows = $this->searchRepository->findRatiosForFilter($filter->where, $filter->params, $filter->types);
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
        $ctx->template->assign('RATIOS', $ratios);
    }

    private function renderHeightFilter(FilterRenderContext $ctx): void
    {
        if (!isset($ctx->mySearch['fields']['height_min']) || !isset($ctx->mySearch['fields']['height_max']) || !$ctx->displayFilters['height']['access']) {
            if (isset($ctx->mySearch['fields']['height_min']) && isset($ctx->mySearch['fields']['height_max'])) {
                unset($ctx->mySearch['fields']['height_min']);
                unset($ctx->mySearch['fields']['height_max']);
            }
            return;
        }

        $filter = $this->searchService->getClauseForFilter('height');

        if (!preg_match('/^image_id IN/', $filter->where)) {
            $cache_key = md5('filter_height_rows' . $ctx->userId . $ctx->userCacheTime . AppInfo::VERSION);
            $item_h    = $this->pool->getItem($cache_key);
            if ($item_h->isHit()) {
                $filter_rows_raw = $item_h->get();
                $heights         = is_array($filter_rows_raw)
                    ? array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $filter_rows_raw))
                    : [];
            } else {
                $heights = $this->searchRepository->findDistinctHeightsForFilter($filter->where, $filter->params, $filter->types);
                $item_h->set($heights);
                $item_h->expiresAfter(86400);
                $this->pool->save($item_h);
            }
        } else {
            $heights = $this->searchRepository->findDistinctHeightsForFilter($filter->where, $filter->params, $filter->types);
        }

        $height = [
            'list' => implode(',', array_map(static fn (int $v): string => (string) $v, $heights)),
            'bounds' => ['min' => $heights[0] ?? 0, 'max' => (int) end($heights)],
            'selected' => [
                'min' => !empty($ctx->mySearch['fields']['height_min']) ? $ctx->mySearch['fields']['height_min'] : ($heights[0] ?? 0),
                'max' => !empty($ctx->mySearch['fields']['height_max']) ? $ctx->mySearch['fields']['height_max'] : (int) end($heights),
            ],
        ];
        $ctx->template->assign('HEIGHT', $height);
        $ctx->height = $height;
    }

    private function renderWidthFilter(FilterRenderContext $ctx): void
    {
        if (!isset($ctx->mySearch['fields']['width_min']) || !isset($ctx->mySearch['fields']['width_max']) || !$ctx->displayFilters['width']['access']) {
            if (isset($ctx->mySearch['fields']['width_min']) && isset($ctx->mySearch['fields']['width_max'])) {
                unset($ctx->mySearch['fields']['width_min']);
                unset($ctx->mySearch['fields']['width_max']);
            }
            return;
        }

        $filter = $this->searchService->getClauseForFilter('width');

        if (!preg_match('/^image_id IN/', $filter->where)) {
            $cache_key = md5('filter_width_rows' . $ctx->userId . $ctx->userCacheTime . AppInfo::VERSION);
            $item_w    = $this->pool->getItem($cache_key);
            if ($item_w->isHit()) {
                $filter_rows_raw = $item_w->get();
                $widths          = is_array($filter_rows_raw)
                    ? array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $filter_rows_raw))
                    : [];
            } else {
                $widths = $this->searchRepository->findDistinctWidthsForFilter($filter->where, $filter->params, $filter->types);
                $item_w->set($widths);
                $item_w->expiresAfter(86400);
                $this->pool->save($item_w);
            }
        } else {
            $widths = $this->searchRepository->findDistinctWidthsForFilter($filter->where, $filter->params, $filter->types);
        }

        $width = [
            'list' => implode(',', array_map(static fn (int $v): string => (string) $v, $widths)),
            'bounds' => ['min' => $widths[0] ?? 0, 'max' => (int) end($widths)],
            'selected' => [
                'min' => !empty($ctx->mySearch['fields']['width_min']) ? $ctx->mySearch['fields']['width_min'] : ($widths[0] ?? 0),
                'max' => !empty($ctx->mySearch['fields']['width_max']) ? $ctx->mySearch['fields']['width_max'] : (int) end($widths),
            ],
        ];
        $ctx->template->assign('WIDTH', $width);
        $ctx->width = $width;
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
