<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Piwigo\Auth\AccessControl;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Event\Search\QsearchGetScopes;
use Piwigo\Event\Search\QsearchPre;
use Piwigo\Event\Tag\RenderTagName;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Permission\PermissionService;
use Piwigo\Search\Event\QsearchBeforeEval;
use Piwigo\Search\Event\QsearchExpressionParsed;
use Piwigo\Search\Event\QsearchGetImagesSqlScopes;
use Piwigo\Search\Event\QsearchResults;
use Piwigo\Search\Inflector\InflectorInterface;
use Piwigo\Search\Projection\Search;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Users\UserService;

/**
 * Search domain business logic, ported from the deleted
 * `include/functions_search.inc.php`'s 17 functions (P23 batch 8c).
 * `get_clause_for_filter()`/`get_items_for_filter()` -- entirely
 * `$page`-coupled (read `$page['search_details']`, written by this
 * service's own `getRegularSearchResults()` return value stored back into
 * `$page` by `Section\SectionPopulator`), zero DB access of their own --
 * were folded as private methods on their single real caller,
 * `Search\SearchFilterRenderer`, instead of onto this class.
 *
 * [SEC-18] The 3 `addslashes()` sites (REGEXP/FULLTEXT/LIKE clause
 * construction in the quick-search token evaluator) are replaced with
 * `SearchRepository::quote()` (the real DBAL driver's own escaping) --
 * these clauses are dynamically OR/AND-joined alongside other,
 * already-safe raw fragments (`QNumericRangeScope`/`QDateRangeScope`'s own
 * `get_sql()`, numeric/date values only) into a single WHERE string, where
 * a `?`-bound parameter can't cleanly compose; `quote()` is the correct,
 * driver-safe way to inline a free-text value into that kind of clause.
 *
 * Every `mixed` below stays that way by design: $search/$field/
 * $allwordsField (and every advanced-search-criterion param derived from
 * them) trace back to Search Projection's own already-documented JSON
 * rules bag; every `list<mixed> $params` matches SearchRepository's own
 * DBAL-bound-parameter rationale.
 */
final readonly class SearchService
{
    /**
     * $tagService/$userService/$preferencesService are optional-with-
     * lazy-default (same reasoning as Mail\MailService's own
     * $webmasterMailProvider): this class has external construction sites
     * across Search/Section (fixed in the same pass as this constructor
     * change) plus several not-yet-migrated Controller/Ws/Admin call sites
     * (out of this phase's domain scope) that would otherwise all need a
     * simultaneous edit for a dependency only reached on the tags/
     * default-language/saveSearch paths.
     */
    public function __construct(
        private AccessControl $accessControl,
        private SearchRepository $repo,
        private PermissionService $permissionService,
        private CategoryService $categoryService,
        private MailerInterface $mailer,
        private HtmlRenderingInterface $htmlRenderer,
        private RedirectServiceInterface $redirectService,
        private SessionService $sessionService,
        private \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
        private \Piwigo\Users\CurrentUser $currentUser,
        private Lang $lang,
        private readonly \Piwigo\Config\CurrentConfig $currentConfig,
        private ?TagService $tagService = null,
        private ?UserService $userService = null,
        private ?\Piwigo\Users\PreferencesService $preferencesService = null,
    ) {}

    public static function getSearchIdPattern(int|string $candidate): ?string
    {
        if (preg_match('/^psk-\d{8}-[a-z0-9]{10}$/i', (string) $candidate) === 1) {
            return 'search_uuid = ?';
        }

        if (preg_match('/^\d+$/', (string) $candidate) === 1) {
            return 'id = ?';
        }

        return null;
    }

    public function getSearchInfo(int|string $candidate): ?Search
    {
        $clausePattern = self::getSearchIdPattern($candidate);
        if ($clausePattern === null) {
            return null;
        }

        return $this->repo->findOneByClause($clausePattern, [$candidate]);
    }

    /**
     * Same as getSearchInfo(), plus the request-context validation the
     * former free function get_search_info() (functions_search.inc.php,
     * P23 batch 8c) applied around it: dies on a malformed candidate,
     * refuses (outside the web-service API) to resolve an old-style
     * numeric-only id once the search row already has a search_uuid (spies
     * shouldn't be able to walk index.php?/search/123, .../124, ...), and
     * hands the resolved id back through $resolvedSearchId for
     * HistoryService::logVisit() to read later, when rendering the
     * "search" section.
     *
     * Legacy Coupling Retirement Track A: $section (batch A5.2e) and
     * $resolvedSearchId (batch A5.2h, replacing the former
     * `$page['search_id']` write) are explicit params instead of
     * `global $page;` -- this method's two real callers are
     * SearchService::getValidatedSearchArray() (reached from
     * SearchFilterRenderer::render(), which passes SectionContext::section,
     * always available there, and returns the resolved id up its own
     * call chain to GalleryController) and Ws\PwgImages::
     * filteredSearchCreate() (a WS method that never runs
     * SectionPopulator, passes null section and no out-param -- matching
     * this gate's own original behavior there: `$page['section']` was
     * never 'search' for a WS request either, so the write never
     * happened for that caller anyway).
     *
     * @param int|null $resolvedSearchId in/out; set to the resolved
     *   search id when $section === 'search', left untouched otherwise
     */
    public function getValidatedSearchInfo(int|string $candidate, ?string $section, ?int &$resolvedSearchId = null): ?Search
    {
        $clausePattern = self::getSearchIdPattern($candidate);
        if ($clausePattern === null) {
            $this->htmlRenderer->fatalError('Invalid search identifier');
        }

        $search = $this->getSearchInfo($candidate);

        if ($search !== null) {
            if (\Piwigo\Core\PageFilterHelper::scriptBasename() !== 'ws' and $clausePattern === 'id = ?' and $search->searchUuid !== null) {
                $this->htmlRenderer->fatalError('this search is not reachable with its id, need the search_uuid instead');
            }

            if ($section === 'search') {
                $resolvedSearchId = $search->id;
            }
        }

        return $search;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getSearchArray(int|string $searchId): array|false
    {
        $search = $this->getSearchInfo($searchId);
        if ($search === null) {
            return false;
        }

        return $search->rules ?? false;
    }

    /**
     * Returns search rules stored in "search" table. Same as
     * getSearchArray(), but resolves the candidate id via
     * getValidatedSearchInfo() (die()/fatal_error()-on-hacking-attempt
     * request-context validation, since this is only meant for a
     * user-supplied search identifier from the URL, unlike getSearchArray()'s
     * own internal callers) and bad_request()s when nothing was found --
     * same composition as the former free function get_search_array()
     * (functions_search.inc.php, P23 batch 8c).
     *
     * @param int|null $resolvedSearchId in/out, see getValidatedSearchInfo()
     * @return array<string, mixed>|false
     */
    public function getValidatedSearchArray(int|string $searchId, ?string $section, ?int &$resolvedSearchId = null): array|false
    {
        $search = $this->getValidatedSearchInfo($searchId, $section, $resolvedSearchId);
        if ($search === null) {
            $this->htmlRenderer->badRequest($this->redirectService, 'this search identifier does not exist');
        }

        return $search->rules ?? false;
    }

    /**
     * Every entry of $imageIdsForFilter is a list<int> id list -- one per
     * active advanced-search criterion.
     *
     * @param  array<string, mixed>  $search
     * @return array{items: list<int>, search_details: array{matching_cat_ids: ?list<int>, matching_tag_ids: ?list<int>, has_filters_filled: bool, image_ids_for_filter: array<string, list<int>>}}
     */
    public function getRegularSearchResults(array $search, string $imagesWhere = ''): array
    {
        $hasFiltersFilled = false;
        $matchingCatIds = null;
        $matchingTagIds = null;

        $forbidden = $this->forbiddenConditionPositional();

        /** @var array<string, list<int>> $imageIdsForFilter */
        $imageIdsForFilter = [];

        $rawFiltersViews = $this->currentConfig->filtersViews() ?? $this->currentConfig->defaultFiltersViews();

        $displayFilters = [];
        foreach ($rawFiltersViews as $filtName => $filtConf) {
            if (is_string($filtName) && is_array($filtConf)) {
                $displayFilters[$filtName] = $filtConf;
            }
        }

        foreach ($displayFilters as $filtName => $filtConf) {
            if (isset($filtConf['access'])) {
                $filtConf['access'] = $filtConf['access'] === 'everybody'
                    || ($filtConf['access'] === 'admins-only' && $this->accessControl->isAdmin())
                    || ($filtConf['access'] === 'registered-users' && $this->accessControl->isClassicUser());
                $displayFilters[$filtName] = $filtConf;
            }
        }

        $rawSearchFields = $search['fields'] ?? null;
        $searchFields = is_array($rawSearchFields) ? $rawSearchFields : [];

        // expert
        $expertField = $searchFields['expert'] ?? null;
        $expertString = (is_array($expertField) && is_string($expertField['string'] ?? null)) ? $expertField['string'] : null;
        if (isset($searchFields['expert']) && $expertString !== null && $expertString !== '' && (bool) ($displayFilters['expert']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $expertItems = $this->getQuickSearchResults($expertString, [])['items'];
            $imageIdsForFilter['expert'] = is_array($expertItems) ? array_values(array_map(intval(...), array_filter($expertItems, is_numeric(...)))) : [];
        }

        // allwords
        $allwordsField = $searchFields['allwords'] ?? null;
        $allwordsWords = is_array($allwordsField) && is_array($allwordsField['words'] ?? null)
            ? array_values(array_filter($allwordsField['words'], is_string(...)))
            : [];
        $allwordsSearchFields = is_array($allwordsField) && is_array($allwordsField['fields'] ?? null)
            ? array_values(array_filter($allwordsField['fields'], is_string(...)))
            : [];
        if (isset($searchFields['allwords']) && is_array($allwordsField) && $allwordsWords !== [] && $allwordsSearchFields !== [] && (bool) ($displayFilters['words']['access'] ?? false)) {
            $hasFiltersFilled = true;
            [$imageIdsForFilter['allwords'], $matchingCatIds, $matchingTagIds] = $this->searchAllwords($allwordsField, $allwordsWords, $allwordsSearchFields, $forbidden);
        }

        // author
        $authorField = $searchFields['author'] ?? null;
        $authorWords = is_array($authorField) && is_array($authorField['words'] ?? null)
            ? array_values(array_filter($authorField['words'], is_string(...)))
            : [];
        if (isset($searchFields['author']) && $authorWords !== [] && (bool) ($displayFilters['author']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $placeholders = implode(',', array_fill(0, count($authorWords), '?'));
            $imageIdsForFilter['author'] = $this->queryImageIdsFor("author IN ({$placeholders})", $authorWords, $forbidden);
        }

        // filetypes
        $filetypesField = $searchFields['filetypes'] ?? null;
        $filetypes = is_array($filetypesField) ? array_values(array_filter($filetypesField, is_string(...))) : [];
        if ($filetypes !== [] && (bool) ($displayFilters['file_type']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $clauses = [];
            $params = [];
            foreach ($filetypes as $ext) {
                $clauses[] = 'path LIKE ?';
                $params[] = '%.' . $ext;
            }

            $imageIdsForFilter['filetypes'] = $this->queryImageIdsFor('(' . implode(' OR ', $clauses) . ')', $params, $forbidden);
        }

        // added_by
        $addedByField = $searchFields['added_by'] ?? null;
        $addedByIds = is_array($addedByField) ? array_values(array_map(intval(...), array_filter($addedByField, is_numeric(...)))) : [];
        if ($addedByIds !== [] && (bool) ($displayFilters['added_by']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $placeholders = implode(',', array_fill(0, count($addedByIds), '?'));
            $imageIdsForFilter['added_by'] = $this->queryImageIdsFor("added_by IN ({$placeholders})", $addedByIds, $forbidden);
        }

        // cat
        $catField = $searchFields['cat'] ?? null;
        $catWords = [];
        if (is_array($catField) && is_array($catField['words'] ?? null)) {
            foreach ($catField['words'] as $catWord) {
                if (is_numeric($catWord)) {
                    $catWords[] = (int) $catWord;
                }
            }
        }
        if (isset($searchFields['cat']) && $catWords !== [] && (bool) ($displayFilters['album']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $catIds = (is_array($catField) && (bool) ($catField['sub_inc'] ?? false))
                ? $this->categoryService->getSubcatIds($catWords)
                : $catWords;

            if ($catIds !== []) {
                $placeholders = implode(',', array_fill(0, count($catIds), '?'));
                $imageIdsForFilter['cat'] = $this->queryImageIdsFor("category_id IN ({$placeholders})", $catIds, $forbidden);
            }
        }

        // date_posted
        $datePostedField = $searchFields['date_posted'] ?? null;
        $datePostedPreset = is_array($datePostedField) && is_string($datePostedField['preset'] ?? null) ? $datePostedField['preset'] : null;
        if ($datePostedPreset !== null && $datePostedPreset !== '' && (bool) ($displayFilters['post_date']['access'] ?? false)) {
            $hasFiltersFilled = true;
            [$clause, $params] = $this->dateFilterClause('date_available', $datePostedPreset, $datePostedField, [
                '24h' => '24 HOUR',
                '7d' => '7 DAY',
                '30d' => '30 DAY',
                '3m' => '3 MONTH',
                '6m' => '6 MONTH',
            ]);
            $imageIdsForFilter['date_posted'] = $this->queryImageIdsFor($clause, $params, $forbidden);
        }

        // date_created
        $dateCreatedField = $searchFields['date_created'] ?? null;
        $dateCreatedPreset = is_array($dateCreatedField) && is_string($dateCreatedField['preset'] ?? null) ? $dateCreatedField['preset'] : null;
        if ($dateCreatedPreset !== null && $dateCreatedPreset !== '' && (bool) ($displayFilters['creation_date']['access'] ?? false)) {
            $hasFiltersFilled = true;
            [$clause, $params] = $this->dateFilterClause('date_creation', $dateCreatedPreset, $dateCreatedField, [
                '7d' => '7 DAY',
                '30d' => '30 DAY',
                '3m' => '3 MONTH',
                '6m' => '6 MONTH',
                '12m' => '12 MONTH',
            ]);
            $imageIdsForFilter['date_created'] = $this->queryImageIdsFor($clause, $params, $forbidden);
        }

        // ratios
        $ratiosField = $searchFields['ratios'] ?? null;
        $ratios = is_array($ratiosField) ? array_values(array_filter($ratiosField, is_string(...))) : [];
        if ($ratios !== [] && (bool) ($displayFilters['ratio']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $clauseForRatio = [
                'Portrait' => 'width/height < 0.95',
                'square' => 'width/height BETWEEN 0.95 AND 1.05',
                'Landscape' => '(width/height > 1.05 AND width/height < 2)',
                'Panorama' => 'width/height >= 2',
            ];
            $clauses = [];
            foreach ($ratios as $r) {
                if (isset($clauseForRatio[$r])) {
                    $clauses[] = $clauseForRatio[$r];
                }
            }

            if ($clauses !== []) {
                $imageIdsForFilter['ratios'] = $this->queryImageIdsFor('(' . implode(' OR ', $clauses) . ')', [], $forbidden);
            }
        }

        // ratings
        $ratingsField = $searchFields['ratings'] ?? null;
        $ratings = is_array($ratingsField) ? array_values(array_filter($ratingsField, is_string(...))) : [];
        if ($this->currentConfig->rateEnabled() && $ratings !== [] && (bool) ($displayFilters['rating']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $clauses = [];
            $ratingParams = [];
            foreach ($ratings as $r) {
                if ((int) $r === 0) {
                    $clauses[] = 'rating_score IS NULL';
                } else {
                    $clauses[] = '(rating_score >= ? AND rating_score < ?)';
                    $ratingParams[] = (int) $r - 1;
                    $ratingParams[] = (int) $r;
                }
            }

            $imageIdsForFilter['ratings'] = $this->queryImageIdsFor('(' . implode(' OR ', $clauses) . ')', $ratingParams, $forbidden);
        }

        // filesize
        $filesizeMinRaw = $searchFields['filesize_min'] ?? null;
        $filesizeMaxRaw = $searchFields['filesize_max'] ?? null;
        if ($filesizeMinRaw !== null && $filesizeMinRaw !== 0 && $filesizeMaxRaw !== null && $filesizeMaxRaw !== 0 && is_numeric($filesizeMinRaw) && is_numeric($filesizeMaxRaw) && (bool) ($displayFilters['file_size']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $imageIdsForFilter['filesize'] = $this->queryImageIdsFor(
                'filesize BETWEEN ? AND ?',
                [(float) $filesizeMinRaw - 100.0, (float) $filesizeMaxRaw + 100.0],
                $forbidden
            );
        }

        // height
        $heightMinRaw = $searchFields['height_min'] ?? null;
        $heightMaxRaw = $searchFields['height_max'] ?? null;
        if ($heightMinRaw !== null && $heightMinRaw !== 0 && $heightMaxRaw !== null && $heightMaxRaw !== 0 && is_scalar($heightMinRaw) && is_scalar($heightMaxRaw) && (bool) ($displayFilters['height']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $imageIdsForFilter['height'] = $this->queryImageIdsFor('height BETWEEN ? AND ?', [$heightMinRaw, $heightMaxRaw], $forbidden);
        }

        // width
        $widthMinRaw = $searchFields['width_min'] ?? null;
        $widthMaxRaw = $searchFields['width_max'] ?? null;
        if ($widthMinRaw !== null && $widthMinRaw !== 0 && $widthMaxRaw !== null && $widthMaxRaw !== 0 && is_scalar($widthMinRaw) && is_scalar($widthMaxRaw) && (bool) ($displayFilters['width']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $imageIdsForFilter['width'] = $this->queryImageIdsFor('width BETWEEN ? AND ?', [$widthMinRaw, $widthMaxRaw], $forbidden);
        }

        // tags
        $tagsField = $searchFields['tags'] ?? null;
        $tagsWords = [];
        if (is_array($tagsField) && is_array($tagsField['words'] ?? null)) {
            foreach ($tagsField['words'] as $tagWord) {
                if (is_numeric($tagWord)) {
                    $tagsWords[] = (int) $tagWord;
                }
            }
        }
        $tagsMode = is_array($tagsField) && is_string($tagsField['mode'] ?? null) ? $tagsField['mode'] : 'AND';
        if (isset($searchFields['tags']) && $tagsWords !== [] && (bool) ($displayFilters['tags']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $tagService = $this->tagService ?? new TagService($this->lang, \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Tag\TagEntity::class), $this->permissionService, new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)), $this->eventDispatcher, $this->currentUser, $this->currentConfig);
            $imageIdsForFilter['tags'] = array_values(array_map(intval(...), array_filter($tagService->getImageIdsForTags(array_map(TagId::from(...), $tagsWords), $tagsMode), is_numeric(...))));
        }

        // custom search
        if ($imagesWhere !== '' && $imagesWhere !== '0') {
            $imageIdsForFilter['custom'] = $this->queryImageIdsFor($imagesWhere, [], $forbidden);
        }

        $items = [];
        if ($imageIdsForFilter !== []) {
            if (count($imageIdsForFilter) > 1) {
                $items = array_values(array_map(intval(...), array_unique(array_intersect(...array_values($imageIdsForFilter)))));
            } else {
                $first = reset($imageIdsForFilter);
                $items = array_map(intval(...), $first);
            }
        }

        if (count($items) > 1) {
            // CurrentConfig::orderBy() (the typed SCHEMA accessor) models a
            // structured {field,dir}[] shape that no real code writes --
            $orderBy = $this->currentConfig->orderBy();
            $items = $this->repo->findIdsByClause('id', Tables::images() . ' i', 'id IN (' . implode(',', array_fill(0, count($items), '?')) . ') ' . $orderBy, $items);
        }

        return [
            'items' => $items,
            'search_details' => [
                'matching_cat_ids' => $matchingCatIds,
                'matching_tag_ids' => $matchingTagIds,
                'has_filters_filled' => $hasFiltersFilled,
                'image_ids_for_filter' => $imageIdsForFilter,
            ],
        ];
    }

    /**
     * SearchRepository's own executors are positional-`?`-only (its own
     * "generic parameterized executor" design, see that class's docblock)
     * -- unlike every other repository in the SQL-modernization initiative,
     * so PermissionService::getSqlConditionFandFAsCondition()'s
     * named-placeholder SqlCondition is rewritten to positional `?`s here,
     * same manual per-element expansion convention this file's own
     * IN-clause callers already use for their own array params (e.g.
     * `implode(',', array_fill(0, count($x), '?'))`). Bare fragment, no
     * prefix -- callers that need a leading " AND " add it themselves.
     *
     * @param  array<string, string>  $conditionFields
     * @return array{0: string, 1: list<mixed>}
     */
    private function positionalCondition(array $conditionFields, bool $forceOneCondition = false): array
    {
        $condition = $this->permissionService->getSqlConditionFandFAsCondition($conditionFields, $forceOneCondition);

        if ($condition->isEmpty()) {
            return ['', []];
        }

        $values = [];
        $sql = preg_replace_callback('/:(\w+)/', static function (array $matches) use ($condition, &$values): string {
            $value = $condition->parameters[$matches[1]];
            if (is_array($value)) {
                $values = [...$values, ...$value];

                return implode(',', array_fill(0, count($value), '?'));
            }

            $values[] = $value;

            return '?';
        }, $condition->sql);
        if ($sql === null) {
            throw new \RuntimeException('positionalCondition(): preg_replace_callback() failed');
        }

        return [$sql, array_values($values)];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function forbiddenConditionPositional(): array
    {
        [$sql, $values] = $this->positionalCondition([
            'forbidden_categories' => 'category_id',
            'visible_categories' => 'category_id',
            'visible_images' => 'id',
        ]);

        return [$sql === '' ? '' : ' AND ' . $sql, $values];
    }

    /**
     * Shared "images matching this WHERE fragment, filtered by the current
     * user's permissions" executor for every advanced-search criterion --
     * all 12 share the exact same
     * `SELECT DISTINCT(id) FROM images i INNER JOIN image_category ic ON id=ic.image_id
     * WHERE <criterion> <forbidden>` shape.
     *
     * @param  list<mixed>  $params
     * @param  array{0: string, 1: list<mixed>}  $forbidden
     * @return list<int>
     */
    private function queryImageIdsFor(string $whereSql, array $params, array $forbidden): array
    {
        [$forbiddenSql, $forbiddenParams] = $forbidden;

        return $this->repo->findIdsByClause(
            'DISTINCT(id)',
            Tables::images() . ' AS i INNER JOIN ' . Tables::imageCategory() . ' AS ic ON id = ic.image_id',
            $whereSql . ' ' . $forbiddenSql,
            [...$params, ...$forbiddenParams]
        );
    }

    /**
     * date_posted/date_created share this exact preset-or-custom-range
     * clause-building logic, differing only in the target column and the
     * preset options list.
     *
     * @param  array<string, string>  $presetOptions
     * @return array{0: string, 1: list<mixed>}
     */
    private function dateFilterClause(string $column, string $preset, mixed $field, array $presetOptions): array
    {
        if (isset($presetOptions[$preset])) {
            return [$column . ' > SUBDATE(NOW(), INTERVAL ' . $presetOptions[$preset] . ')', []];
        }

        $custom = [];
        if (is_array($field) && is_array($field['custom'] ?? null)) {
            foreach ($field['custom'] as $v) {
                if (is_string($v)) {
                    $custom[] = $v;
                } elseif (is_int($v)) {
                    $custom[] = (string) $v;
                }
            }
        }

        if ($preset === 'custom' && $custom !== []) {
            $subclauses = [];
            $params = [];
            // $customDates (a flip, kept only for its isset() lookups
            // below) canonicalizes a purely-numeric string value (e.g.
            // (string) 20250101) into an int array key -- confirmed live,
            // array_keys() on it would hand the loop below a genuine int,
            // not the string substr() requires. Deduplicating the
            // iteration list separately via array_unique() (which, unlike
            // array_flip()'s keys, never touches value types) sidesteps
            // that entirely.
            $customDates = array_flip($custom);

            foreach (array_unique($custom) as $customDate) {
                $begin = $end = null;
                $ymd = substr($customDate, 0, 1);
                if ($ymd === 'y') {
                    $year = substr($customDate, 1);
                    $begin = $year . '-01-01 00:00:00';
                    $end = $year . '-12-31 23:59:59';
                } elseif ($ymd === 'm') {
                    [$year, $month] = explode('-', substr($customDate, 1));
                    if (! isset($customDates['y' . $year])) {
                        $begin = $year . '-' . $month . '-01 00:00:00';
                        $end = $year . '-' . $month . '-' . cal_days_in_month(\CAL_GREGORIAN, (int) $month, (int) $year) . ' 23:59:59';
                    }
                } elseif ($ymd === 'd') {
                    [$year, $month, $day] = explode('-', substr($customDate, 1));
                    if (! isset($customDates['y' . $year]) && ! isset($customDates['m' . $year . '-' . $month])) {
                        $begin = $year . '-' . $month . '-' . $day . ' 00:00:00';
                        $end = $year . '-' . $month . '-' . $day . ' 23:59:59';
                    }
                }

                if ($begin !== null) {
                    $subclauses[] = '(' . $column . ' BETWEEN ? AND ?)';
                    $params[] = $begin;
                    $params[] = $end;
                }
            }

            return ['(' . implode(' OR ', $subclauses) . ')', $params];
        }

        return ['1=1', []];
    }

    /**
     * @param  array<array-key, mixed>  $allwordsField
     * @param  list<string>  $words
     * @param  list<string>  $searchFields
     * @param  array{0: string, 1: list<mixed>}  $forbidden
     * @return array{0: list<int>, 1: ?list<int>, 2: ?list<int>}
     */
    private function searchAllwords(array $allwordsField, array $words, array $searchFields, array $forbidden): array
    {
        $fields = array_intersect(['file', 'name', 'comment', 'author'], $searchFields);

        $catFieldsDictionary = [
            'cat-title' => 'name',
            'cat-desc' => 'comment',
        ];
        $catFields = array_intersect(array_keys($catFieldsDictionary), $searchFields);

        $wordClauses = [];
        $catIdsByWord = [];
        $tagIdsByWord = [];

        foreach ($words as $word) {
            $fieldClauses = [];
            $params = [];
            foreach ($fields as $field) {
                $fieldClauses[] = $field . ' LIKE ?';
                $params[] = '%' . $word . '%';
            }

            if ($catFields !== []) {
                $catFieldClauses = [];
                $catParams = [];
                foreach ($catFields as $catField) {
                    $catFieldClauses[] = $catFieldsDictionary[$catField] . ' LIKE ?';
                    $catParams[] = '%' . $word . '%';
                }

                $catIds = $this->repo->findIdsByClause('id', Tables::categories(), implode(' OR ', $catFieldClauses), $catParams);
                $catIdsByWord[$word] = $catIds;
                if ($catIds !== []) {
                    $placeholders = implode(',', array_fill(0, count($catIds), '?'));
                    $catImageIds = $this->repo->findIdsByClause('image_id', Tables::imageCategory(), "category_id IN ({$placeholders})", $catIds);
                    if ($catImageIds !== []) {
                        $inPlaceholders = implode(',', array_fill(0, count($catImageIds), '?'));
                        $fieldClauses[] = "id IN ({$inPlaceholders})";
                        $params = [...$params, ...$catImageIds];
                    }
                }
            }

            if (in_array('tags', $searchFields, true)) {
                $tagIds = $this->repo->findIdsByClause('id', Tables::tags(), 'name LIKE ?', ['%' . $word . '%']);
                $tagIdsByWord[$word] = $tagIds;
                if ($tagIds !== []) {
                    $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
                    $tagImageIds = $this->repo->findIdsByClause('image_id', Tables::imageTag(), "tag_id IN ({$placeholders})", $tagIds);
                    if ($tagImageIds !== []) {
                        $inPlaceholders = implode(',', array_fill(0, count($tagImageIds), '?'));
                        $fieldClauses[] = "id IN ({$inPlaceholders})";
                        $params = [...$params, ...$tagImageIds];
                    }
                }
            }

            if ($fieldClauses !== []) {
                $wordClauses[] = [
                    'sql' => implode("\n          OR ", $fieldClauses),
                    'params' => $params,
                ];
            }
        }

        $allwordsMode = (is_string($allwordsField['mode'] ?? null) && in_array($allwordsField['mode'], ['OR', 'AND'], true))
            ? $allwordsField['mode']
            : 'AND';

        $filterClauseParts = array_map(static fn (array $c): string => '(' . $c['sql'] . ')', $wordClauses);
        $filterClause = "\n         " . implode("\n         " . $allwordsMode . "\n         ", $filterClauseParts);
        $allParams = array_merge(...array_map(static fn (array $c): array => $c['params'], $wordClauses));
        [$forbiddenSql, $forbiddenParams] = $forbidden;

        $imageIds = $this->repo->findIdsByClause(
            'DISTINCT(id)',
            Tables::images() . ' AS i INNER JOIN ' . Tables::imageCategory() . ' AS ic ON id = ic.image_id',
            $filterClause . ' ' . $forbiddenSql,
            [...$allParams, ...$forbiddenParams]
        );

        $matchingCatIds = null;
        if ($catIdsByWord !== []) {
            $matchingCatIds = array_values(array_unique(array_merge(...array_values($catIdsByWord))));
        }

        $matchingTagIds = null;
        if ($tagIdsByWord !== []) {
            $matchingTagIds = array_values(array_unique(array_merge(...array_values($tagIdsByWord))));
        }

        return [$imageIds, $matchingCatIds, $matchingTagIds];
    }

    /**
     * [SEC-18] Free-text search terms are quoted via
     * {@see SearchRepository::quote()} (real DBAL driver escaping),
     * replacing the original's `addslashes()`.
     *
     * @param  string[]  $fields
     * @return non-falsy-string[]
     */
    public function qsearchGetTextTokenSearchSql(QSingleToken $token, array $fields): array
    {
        $clauses = [];
        $variants = array_merge([$token->term], $token->variants);
        $fts = [];
        foreach ($variants as $variant) {
            $useFt = mb_strlen($variant) > 3;
            if ((bool) ($token->modifier & QSingleToken::QST_WILDCARD_BEGIN)) {
                $useFt = false;
            }

            if (($token->modifier & (QSingleToken::QST_QUOTED | QSingleToken::QST_WILDCARD_END)) === (QSingleToken::QST_QUOTED | QSingleToken::QST_WILDCARD_END)) {
                $useFt = false;
            }

            if ($useFt) {
                $parts = preg_split('/[' . preg_quote('-\'!"#$%&()*+,./:;<=>?@[\]^`{|}~', '/') . ']+/', $variant);
                if ($parts === false) {
                    throw new \Exception('qsearchGetTextTokenSearchSql(): preg_split() failed');
                }

                $max = max(array_map(mb_strlen(...), $parts));
                if ($max < 4) {
                    $useFt = false;
                }
            }

            if (! $useFt) {
                // getDbVersion() is a property read on the already-connected
                // driver handle, not a query -- no memoization needed.
                $dbVersion = $this->repo->getDbVersion();
                $useRegexpICU = preg_match('/mariadb/i', $dbVersion) !== 1 && version_compare($dbVersion, '8.0.4', '>');

                // A single literal backslash here ('\\b' is a 2-char PHP
                // string: backslash + b) -- quote() below does its own SQL
                // string-literal escaping (doubling it to '\\\\b' in the
                // SQL text), which MySQL's parser then reduces back to one
                // literal backslash before REGEXP ever sees it. Confirmed
                // live: starting from an already-doubled '\\\\b' here
                // (4-char PHP string) round-trips through quote() into 4
                // literal backslashes, which ICU regex parses as an
                // escaped literal backslash + a literal 'b' -- never a
                // \b word-boundary token -- so it silently matched 0 rows.
                $pre = ((bool) ($token->modifier & QSingleToken::QST_WILDCARD_BEGIN)) ? '' : ($useRegexpICU ? '\\b' : '[[:<:]]');
                $post = ((bool) ($token->modifier & QSingleToken::QST_WILDCARD_END)) ? '' : ($useRegexpICU ? '\\b' : '[[:>:]]');
                foreach ($fields as $field) {
                    $clauses[] = $field . ' REGEXP ' . $this->repo->quote($pre . preg_quote($variant) . $post);
                }
            } else {
                $ft = $variant;
                if ((bool) ($token->modifier & QSingleToken::QST_QUOTED)) {
                    $ft = '"' . $ft . '"';
                }

                if ((bool) ($token->modifier & QSingleToken::QST_WILDCARD_END)) {
                    $ft .= '*';
                }

                $fts[] = $ft;
            }
        }

        if ($fts !== []) {
            $clauses[] = 'MATCH(' . implode(', ', $fields) . ') AGAINST(' . $this->repo->quote(implode(' ', $fts)) . ' IN BOOLEAN MODE)';
        }

        return $clauses;
    }

    /**
     * [SEC-18] The LIKE clause's free-text term is quoted via
     * {@see SearchRepository::quote()}, replacing the original's
     * `addslashes()` + manual `%`/`_` escaping (`quote()` escapes the
     * whole literal; LIKE wildcards `%`/`_` are still escaped separately
     * since they're meaningful to LIKE itself, not the SQL string
     * literal).
     */
    public function qsearchGetImages(QExpression $expr, QResults $qsr): void
    {
        $qsr->images_iids = array_fill(0, count($expr->stokens), []);

        for ($i = 0; $i < count($expr->stokens); $i++) {
            $token = $expr->stokens[$i];
            $scope = $token->scope;
            $scopeId = $scope !== null ? $scope->id : 'photo';
            $clauses = [];

            $like = str_replace(['%', '_'], ['\\%', '\\_'], $token->term);
            $fileLike = 'CONVERT(file, CHAR) LIKE ' . $this->repo->quote('%' . $like . '%');

            switch ($scopeId) {
                case 'photo':
                    $clauses[] = $fileLike;
                    $clauses = array_merge($clauses, $this->qsearchGetTextTokenSearchSql($token, ['name', 'comment']));

                    break;

                case 'file':
                    $clauses[] = $fileLike;

                    break;
                case 'author':
                    if ((bool) strlen($token->term)) {
                        $clauses = array_merge($clauses, $this->qsearchGetTextTokenSearchSql($token, ['author']));
                    } elseif ((bool) ($token->modifier & QSingleToken::QST_WILDCARD)) {
                        $clauses[] = 'author IS NOT NULL';
                    } else {
                        $clauses[] = 'author IS NULL';
                    }

                    break;
                case 'width':
                case 'height':
                    assert($scope !== null);
                    $clauses[] = $scope->get_sql($scopeId, $token);

                    break;
                case 'ratio':
                    assert($scope !== null);
                    $clauses[] = $scope->get_sql('width/height', $token);

                    break;
                case 'size':
                    assert($scope !== null);
                    $clauses[] = $scope->get_sql('width*height', $token);

                    break;
                case 'hits':
                    assert($scope !== null);
                    $clauses[] = $scope->get_sql('hit', $token);

                    break;
                case 'score':
                    assert($scope !== null);
                    $clauses[] = $scope->get_sql('rating_score', $token);

                    break;
                case 'filesize':
                    assert($scope !== null);
                    $clauses[] = $scope->get_sql('1024*filesize', $token);

                    break;
                case 'created':
                    assert($scope !== null);
                    $clauses[] = $scope->get_sql('date_creation', $token);

                    break;
                case 'posted':
                    assert($scope !== null);
                    $clauses[] = $scope->get_sql('date_available', $token);

                    break;
                case 'id':
                    assert($scope !== null);
                    $clauses[] = $scope->get_sql($scopeId, $token);

                    break;
                default:
                    $clausesAfterHook = $this->eventDispatcher->dispatchChange(new QsearchGetImagesSqlScopes($clauses, $token, $expr))
                        ->clauses;
                    $clauses = array_values(array_filter($clausesAfterHook, is_string(...)));

                    break;
            }

            if ($clauses !== []) {
                $qsr->images_iids[$i] = $this->repo->findIdsByClause('id', Tables::images() . ' i', '(' . implode("\n OR ", $clauses) . ')');
            }
        }
    }

    public function qsearchGetTags(QExpression $expr, QResults $qsr): void
    {
        $tokenTagIds = $qsr->tag_iids = array_fill(0, count($expr->stokens), []);
        $allTags = [];

        for ($i = 0; $i < count($expr->stokens); $i++) {
            $token = $expr->stokens[$i];
            if (isset($token->scope) && $token->scope->id !== 'tag') {
                continue;
            }

            if ($token->term === '') {
                continue;
            }

            $clauses = $this->qsearchGetTextTokenSearchSql($token, ['name']);
            $rows = $this->repo->findRowsByClause(Tables::tags(), '(' . implode("\n OR ", $clauses) . ')');
            foreach ($rows as $tag) {
                if (! is_numeric($tag['id'])) {
                    continue;
                }

                $tagId = (int) $tag['id'];
                $tokenTagIds[$i][] = $tagId;
                $allTags[$tagId] = $tag;
            }
        }

        for ($i = 0; $i < count($expr->stokens) - 1; $i++) {
            if ((strlen($expr->stokens[$i]->term) <= 3 || strlen($expr->stokens[$i + 1]->term) <= 3)
                && ($expr->stoken_modifiers[$i] & (QSingleToken::QST_QUOTED | QSingleToken::QST_WILDCARD)) === 0
                && ($expr->stoken_modifiers[$i + 1] & (QSingleToken::QST_BREAK | QSingleToken::QST_QUOTED | QSingleToken::QST_WILDCARD)) === 0) {
                $common = array_intersect($tokenTagIds[$i], $tokenTagIds[$i + 1]);
                if ((bool) count($common)) {
                    $tokenTagIds[$i] = $tokenTagIds[$i + 1] = $common;
                }
            }
        }

        $positiveIds = $notIds = [];
        for ($i = 0; $i < count($expr->stokens); $i++) {
            $tagIds = array_values($tokenTagIds[$i]);
            $token = $expr->stokens[$i];

            if ($tagIds !== []) {
                $qsr->tag_iids[$i] = $this->repo->findIdsByClause(
                    'image_id',
                    Tables::imageTag(),
                    'tag_id IN (' . implode(',', array_fill(0, count($tagIds), '?')) . ') GROUP BY image_id',
                    $tagIds
                );
                if ((bool) ($expr->stoken_modifiers[$i] & QSingleToken::QST_NOT)) {
                    $notIds = array_merge($notIds, $tagIds);
                } elseif (strlen($token->term) > 2 || count($expr->stokens) === 1 || isset($token->scope) || (bool) ($token->modifier & (QSingleToken::QST_WILDCARD | QSingleToken::QST_QUOTED))) {
                    $positiveIds = array_merge($positiveIds, $tagIds);
                }
            } elseif (isset($token->scope) && $token->scope->id === 'tag' && strlen($token->term) === 0) {
                if ((bool) ($token->modifier & QSingleToken::QST_WILDCARD)) {
                    $qsr->tag_iids[$i] = $this->repo->findIdsByClause('DISTINCT image_id', Tables::imageTag(), '1=1');
                } else {
                    $qsr->tag_iids[$i] = $this->repo->findIdsByClause(
                        'id',
                        Tables::images() . ' LEFT JOIN ' . Tables::imageTag() . ' ON id=image_id',
                        'image_id IS NULL'
                    );
                }
            }
        }

        $allTags = array_intersect_key($allTags, array_flip(array_diff($positiveIds, $notIds)));
        usort($allTags, $this->htmlRenderer->tagAlphaCompare(...));
        foreach ($allTags as &$tag) {
            $nameEvent = $this->eventDispatcher->dispatchChange(new RenderTagName(is_string($tag['name']) ? $tag['name'] : '', $tag));
            $tag['name'] = $nameEvent->tagName;
        }

        unset($tag);
        $qsr->all_tags = $allTags;
        $qsr->tag_ids = $tokenTagIds;
    }

    public function qsearchGetCategories(QExpression $expr, QResults $qsr): void
    {
        // P23 batch 3: user_cache_categories's INNER JOIN below used to
        // filter to "categories this user's cache row exists for" -- exactly
        // the set build_user()/getuserdata() (include/functions_user.inc.php)
        // already computes into $user['forbidden_categories'] (private/locked
        // categories via calculate_permissions(), extended with 0-image
        // categories for non-admins -- verified by tracing getuserdata()'s
        // cache-population branch, which appends to the same
        // forbidden_categories value it later writes into
        // user_cache_categories via get_computed_categories()/\Piwigo\Db\MysqliDb::massInserts()).
        // Reading it directly here needs no query at all, on either a
        // cache-hit or cache-miss request.
        $forbiddenCategories = $this->currentUser->get()
            ->forbiddenCategories;
        $forbiddenIds = array_values(array_map(intval(...), array_filter(explode(',', $forbiddenCategories), is_numeric(...))));
        if ($forbiddenIds === []) {
            $forbiddenIds = [0];
        }
        $forbiddenPlaceholders = implode(',', array_fill(0, count($forbiddenIds), '?'));

        $tokenCatIds = $qsr->cat_iids = array_fill(0, count($expr->stokens), []);
        $allCats = [];

        for ($i = 0; $i < count($expr->stokens); $i++) {
            $token = $expr->stokens[$i];
            if (isset($token->scope) && $token->scope->id !== 'category') {
                continue;
            }

            if ($token->term === '') {
                continue;
            }

            $clauses = $this->qsearchGetTextTokenSearchSql($token, ['name', 'comment']);
            $rows = $this->repo->findRowsByClause(
                Tables::categories(),
                '(' . implode("\n OR ", $clauses) . ') AND id NOT IN (' . $forbiddenPlaceholders . ')',
                $forbiddenIds
            );
            foreach ($rows as $cat) {
                if (! is_numeric($cat['id'])) {
                    continue;
                }

                $catId = (int) $cat['id'];
                $tokenCatIds[$i][] = $catId;
                $allCats[$catId] = $cat;
            }
        }

        for ($i = 0; $i < count($expr->stokens) - 1; $i++) {
            if ((strlen($expr->stokens[$i]->term) <= 3 || strlen($expr->stokens[$i + 1]->term) <= 3)
                && ($expr->stoken_modifiers[$i] & (QSingleToken::QST_QUOTED | QSingleToken::QST_WILDCARD)) === 0
                && ($expr->stoken_modifiers[$i + 1] & (QSingleToken::QST_BREAK | QSingleToken::QST_QUOTED | QSingleToken::QST_WILDCARD)) === 0) {
                $common = array_intersect($tokenCatIds[$i], $tokenCatIds[$i + 1]);
                if ((bool) count($common)) {
                    $tokenCatIds[$i] = $tokenCatIds[$i + 1] = $common;
                }
            }
        }

        $positiveIds = $notIds = [];
        for ($i = 0; $i < count($expr->stokens); $i++) {
            $catIds = array_values($tokenCatIds[$i]);
            $token = $expr->stokens[$i];

            if ($catIds !== []) {
                if ($this->currentConfig->quickSearchIncludeSubAlbums()) {
                    $subcatIds = $this->categoryService->getSubcatIds($catIds);
                    $catIds = $subcatIds !== []
                        ? $this->repo->findIdsByClause(
                            'id',
                            Tables::categories(),
                            'id IN (' . implode(',', array_fill(0, count($subcatIds), '?')) . ') AND id NOT IN (' . $forbiddenPlaceholders . ')',
                            [...$subcatIds, ...$forbiddenIds]
                        )
                        : [];
                }

                $qsr->cat_iids[$i] = $catIds !== [] ? $this->repo->findIdsByClause(
                    'image_id',
                    Tables::imageCategory(),
                    'category_id IN (' . implode(',', array_fill(0, count($catIds), '?')) . ') GROUP BY image_id',
                    $catIds
                ) : [];
                if ((bool) ($expr->stoken_modifiers[$i] & QSingleToken::QST_NOT)) {
                    $notIds = array_merge($notIds, $catIds);
                } elseif (strlen($token->term) > 2 || count($expr->stokens) === 1 || isset($token->scope) || (bool) ($token->modifier & (QSingleToken::QST_WILDCARD | QSingleToken::QST_QUOTED))) {
                    $positiveIds = array_merge($positiveIds, $catIds);
                }
            } elseif (isset($token->scope) && $token->scope->id === 'category' && strlen($token->term) === 0) {
                if ((bool) ($token->modifier & QSingleToken::QST_WILDCARD)) {
                    $qsr->cat_iids[$i] = $this->repo->findIdsByClause('DISTINCT image_id', Tables::imageCategory(), '1=1');
                } else {
                    $qsr->cat_iids[$i] = $this->repo->findIdsByClause(
                        'id',
                        Tables::images() . ' LEFT JOIN ' . Tables::imageCategory() . ' ON id=image_id',
                        'image_id IS NULL'
                    );
                }
            }
        }

        $allCats = array_intersect_key($allCats, array_flip(array_diff($positiveIds, $notIds)));
        usort($allCats, $this->htmlRenderer->tagAlphaCompare(...));
        foreach ($allCats as &$cat) {
            $nameEvent = $this->eventDispatcher->dispatchChange(new RenderCategoryName(is_string($cat['name']) ? $cat['name'] : '', $cat));
            $cat['name'] = $nameEvent->categoryName;
        }

        unset($cat);
        $qsr->all_cats = $allCats;
        $qsr->cat_ids = $tokenCatIds;
    }

    /**
     * Pure computation over `QResults` -- no DB access of its own. Ported
     * verbatim (recursive AND/OR/NOT boolean-expression evaluation over
     * already-fetched id sets).
     *
     * @param  string[]  $ignoredTerms
     * @return array<int, int>
     */
    public function qsearchEval(QMultiToken $expr, QResults $qsr, bool &$qualifies, array &$ignoredTerms): array
    {
        $qualifies = false;
        $ignoredTerms = [];

        $ids = $notIds = [];

        for ($i = 0; $i < count($expr->tokens); $i++) {
            $crt = $expr->tokens[$i];
            $crtQualifies = false;
            $crtIgnoredTerms = [];
            if ($crt instanceof QSingleToken) {
                assert($crt->idx !== null);
                $crtIds = $qsr->iids[$crt->idx] = array_unique(array_merge(
                    $qsr->images_iids[$crt->idx],
                    $qsr->cat_iids[$crt->idx],
                    $qsr->tag_iids[$crt->idx]
                ));
                $crtQualifies = count($crtIds) > 0 || count($qsr->tag_ids[$crt->idx]) > 0;
                $crtIgnoredTerms = $crtQualifies ? [] : [(string) $crt];
            } else {
                $crtIds = $this->qsearchEval($crt, $qsr, $crtQualifies, $crtIgnoredTerms);
            }

            $modifier = $crt->modifier;
            if ((bool) ($modifier & QSingleToken::QST_NOT)) {
                $notIds = array_unique(array_merge($notIds, $crtIds));
            } else {
                $ignoredTerms = array_merge($ignoredTerms, $crtIgnoredTerms);
                if ((bool) ($modifier & QSingleToken::QST_OR)) {
                    $ids = array_unique(array_merge($ids, $crtIds));
                    $qualifies = $qualifies || $crtQualifies;
                } elseif ($crtQualifies) {
                    $ids = $qualifies ? array_intersect($ids, $crtIds) : $crtIds;
                    $qualifies = true;
                }
            }
        }

        if ((bool) count($notIds)) {
            $ids = array_diff($ids, $notIds);
        }

        return $ids;
    }

    /**
     * Same "widened by the qsearch_results plugin hook" rationale as
     * getQuickSearchResultsNoCache() below.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function getQuickSearchResults(string $q, array $options): array
    {
        $user = $this->currentUser->get();

        $pool = \Piwigo\Cache\CachePools::searchResults();
        $cacheKey = md5(serialize([
            strtolower($q),
            $this->currentConfig->orderBy(),
            $user->id->value,
            isset($options['permissions']) ? (bool) $options['permissions'] : true,
            $options['images_where'] ?? '',
        ]));
        $cacheItem = $pool->getItem('quick_search_' . $cacheKey);
        if ($cacheItem->isHit()) {
            $cached = $cacheItem->get();
            if (is_array($cached)) {
                /** @var array<string, mixed> $cached */
                return $cached;
            }
        }

        $res = $this->getQuickSearchResultsNoCache($q, $options);
        unset($res['debug']);

        if ((bool) count($res['items'])) {
            $cacheItem->set($res);
            $pool->save($cacheItem);
        }

        return $res;
    }

    /**
     * `items`/`qs` may be widened or replaced by the `qsearch_results` hook
     * (arbitrary plugin-supplied `mixed`, see the `trigger_change()` merge
     * below) -- not narrowable further than this.
     *
     * @param  array<string, mixed>  $options
     * @return array{items: array<int, mixed>, qs: array<string, mixed>, debug: list<string>, ...<string, mixed>}
     */
    public function getQuickSearchResultsNoCache(string $q, array $options): array
    {
        $q = trim(stripslashes($q));
        $searchResults = [
            'items' => [],
            'qs' => [
                'q' => $q,
                'unmatched_terms' => [],
            ],
        ];

        /** @var list<string> $debug */
        $debug = [];

        $q = $this->eventDispatcher->dispatchChange(new QsearchPre($q))
            ->q;

        $scopes = [];
        $scopes[] = new QSearchScope('tag', ['tags']);
        $scopes[] = new QSearchScope('photo', ['photos']);
        $scopes[] = new QSearchScope('file', ['filename']);
        $scopes[] = new QSearchScope('author', [], true);
        $scopes[] = new QNumericRangeScope('width', []);
        $scopes[] = new QNumericRangeScope('height', []);
        $scopes[] = new QNumericRangeScope('ratio', [], false, 0.001);
        $scopes[] = new QNumericRangeScope('size', []);
        $scopes[] = new QNumericRangeScope('filesize', []);
        $scopes[] = new QNumericRangeScope('hits', ['hit', 'visit', 'visits']);
        $scopes[] = new QNumericRangeScope('score', ['rating'], true);
        $scopes[] = new QNumericRangeScope('id', []);

        $createdDateAliases = ['taken', 'shot'];
        $postedDateAliases = ['added'];
        if ($this->currentConfig->calendarDatefield() === 'date_creation') {
            $createdDateAliases[] = 'date';
        } else {
            $postedDateAliases[] = 'date';
        }

        $scopes[] = new QDateRangeScope('created', $createdDateAliases, true);
        $scopes[] = new QDateRangeScope('posted', $postedDateAliases);

        $scopesAfterHook = $this->eventDispatcher->dispatchChange(new QsearchGetScopes($scopes))
            ->scopes;
        $scopes = array_values(array_filter($scopesAfterHook, static fn (mixed $s): bool => $s instanceof QSearchScope));
        $expression = new QExpression($q, $scopes);

        $inflector = null;
        $userService = $this->userService ?? new UserService($this->lang, \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Users\UserInfoEntity::class), \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Group\GroupEntity::class), $this->mailer, new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)), $this->htmlRenderer, DbConnection::build(), $this->sessionService, $this->eventDispatcher, \Piwigo\Config\DeploymentPolicy::current(), $this->currentUser, $this->currentConfig);
        $langCode = substr($userService->getDefaultLanguage(), 0, 2);
        $className = '\\Piwigo\\Search\\Inflector\\Inflector_' . $langCode;
        if (class_exists($className)) {
            $inflector = new $className();
            if (! $inflector instanceof InflectorInterface) {
                throw new \LogicException("qsearch: {$className} does not implement InflectorInterface");
            }

            foreach ($expression->stokens as $token) {
                if (isset($token->scope) && ! $token->scope->is_text) {
                    continue;
                }

                if (strlen($token->term) > 2
                    && ($token->modifier & (QSingleToken::QST_QUOTED | QSingleToken::QST_WILDCARD)) === 0
                    && strcspn($token->term, '\'0123456789') === strlen($token->term)) {
                    $token->variants = array_unique(array_diff($inflector->get_variants($token->term), [$token->term]));
                }
            }
        }

        $this->eventDispatcher->dispatchNotify(new QsearchExpressionParsed($expression));

        if (count($expression->stokens) === 0) {
            $searchResults['debug'] = $debug;

            return $searchResults;
        }

        $qsr = new QResults();
        $this->qsearchGetTags($expression, $qsr);
        $this->qsearchGetCategories($expression, $qsr);
        $this->qsearchGetImages($expression, $qsr);

        $this->eventDispatcher->dispatchNotify(new QsearchBeforeEval($expression, $qsr));

        $tmp = false;
        $unmatchedTerms = [];
        $ids = $this->qsearchEval($expression, $qsr, $tmp, $unmatchedTerms);
        $searchResults['qs']['unmatched_terms'] = $unmatchedTerms;

        $debug[] = "<!--\nparsed: " . htmlspecialchars((string) $expression);
        $debug[] = count($expression->stokens) . ' tokens';
        for ($i = 0; $i < count($expression->stokens); $i++) {
            $debug[] = htmlspecialchars((string) $expression->stokens[$i]) . ': ' . count($qsr->tag_ids[$i]) . ' tags, ' . count($qsr->tag_iids[$i]) . ' tiids, ' . count($qsr->images_iids[$i]) . ' iiids, ' . count($qsr->iids[$i]) . ' iids'
                . ' modifier:' . dechex($expression->stoken_modifiers[$i])
                . ($expression->stokens[$i]->variants !== [] ? ' variants: ' . htmlspecialchars(implode(', ', $expression->stokens[$i]->variants)) : '');
        }

        $debug[] = 'before perms ' . count($ids);

        $searchResults['qs']['matching_tags'] = $qsr->all_tags;
        $searchResults['qs']['matching_cats'] = $qsr->all_cats;
        $searchResultsAfterHook = $this->eventDispatcher->dispatchChange(new QsearchResults($searchResults, $expression, $qsr))
            ->searchResults;
        foreach ($searchResultsAfterHook as $hookKey => $hookValue) {
            if (is_string($hookKey)) {
                $searchResults[$hookKey] = $hookValue;
            }
        }

        $searchResults['items'] = is_array($searchResults['items'])
            ? array_values($searchResults['items'])
            : [];

        if (is_array($searchResults['qs'])) {
            $qs = [];
            foreach ($searchResults['qs'] as $qsKey => $qsValue) {
                if (is_string($qsKey)) {
                    $qs[$qsKey] = $qsValue;
                }
            }

            $searchResults['qs'] = $qs;
        } else {
            $searchResults['qs'] = [
                'q' => $q,
                'unmatched_terms' => $unmatchedTerms,
            ];
        }

        $extraIds = [];
        foreach ($searchResults['items'] as $extraId) {
            if (is_numeric($extraId)) {
                $extraIds[] = (int) $extraId;
            }
        }

        $ids = array_merge($ids, $extraIds);

        if ($ids === []) {
            $debug[] = '-->';
            $searchResults['debug'] = $debug;

            return $searchResults;
        }

        $permissions = ! isset($options['permissions']) || (bool) $options['permissions'];

        $whereClauses = [];
        $params = [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $whereClauses[] = "i.id IN ({$placeholders})";
        $params = [...$params, ...$ids];
        if (isset($options['images_where']) && $options['images_where'] !== '' && is_scalar($options['images_where'])) {
            $whereClauses[] = '(' . (string) $options['images_where'] . ')';
        }

        if ($permissions) {
            [$permissionSql, $permissionValues] = $this->positionalCondition([
                'forbidden_categories' => 'category_id',
                'forbidden_images' => 'i.id',
            ], true);
            $whereClauses[] = $permissionSql;
            $params = [...$params, ...$permissionValues];
        }

        $from = Tables::images() . ' i';
        if ($permissions) {
            $from .= ' INNER JOIN ' . Tables::imageCategory() . ' AS ic ON id = ic.image_id';
        }

        // `SELECT DISTINCT(id) ... ORDER BY <col not in select>` is invalid
        // under ONLY_FULL_GROUP_BY -- Piwigo\Db\DbConnection deliberately
        // doesn't strip that sql_mode the way the legacy dblayer does (see
        // its own docblock), so `GROUP BY id` (functionally dependent via
        // the primary key) replaces DISTINCT here, same fix as
        // CalendarRepository::findImageIds().
        $orderBy = $this->currentConfig->orderBy();
        $ids = $this->repo->findIdsByClause('id', $from, implode("\n AND ", $whereClauses) . "\nGROUP BY id\n" . $orderBy, $params);

        $debug[] = count($ids) . ' final photo count -->';

        $searchResults['items'] = $ids;
        $searchResults['debug'] = $debug;

        return $searchResults;
    }

    /**
     * @return string[]|null
     */
    public static function splitAllwords(string $rawAllwords): ?array
    {
        $words = null;

        $rawAllwords = trim($rawAllwords, " \n\r\t\v\x00.");

        if (preg_match('/^\s*$/', $rawAllwords) !== 1) {
            $dropCharMatch = [';', '&', '(', ')', '<', '>', '`', '\'', '"', '|', ',', '@', '?', '%', '. ', '[', ']', '{', '}', ':', '\\', '/', '=', '\'', '!', '*'];
            $dropCharReplace = [' ', ' ', ' ', ' ', ' ', ' ', '', '', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', '', ' ', ' ', ' ', ' ', ' '];

            $split = preg_split('/\s+/', str_replace($dropCharMatch, $dropCharReplace, $rawAllwords));
            if ($split === false) {
                throw new \Exception('splitAllwords(): preg_split() failed');
            }

            $words = array_unique($split);
        }

        return $words;
    }

    public function getAvailableSearchUuid(): string
    {
        $candidate = 'psk-' . date('Ymd') . '-' . $this->sessionService->generateKey(10);

        if ($this->repo->countByUuid($candidate) === 0) {
            return $candidate;
        }

        return $this->getAvailableSearchUuid();
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{0: string, 1: string}
     */
    public function saveSearch(array $rules, UrlServiceInterface $urlService, ?int $forkedFrom = null): array
    {
        $dbNow = $this->repo->now();
        $searchUuid = $this->getAvailableSearchUuid();

        $userId = $this->currentUser->get()
            ->id->value;

        $this->repo->insertSearch($rules, $dbNow, $userId, $searchUuid, $forkedFrom);

        if (! $this->accessControl->isAGuest() && ! $this->accessControl->isGeneric()) {
            $rulesFields = $rules['fields'] ?? [];
            $preferencesService = $this->preferencesService ?? new \Piwigo\Users\PreferencesService(\Piwigo\Db\EntityManagerFactory::build(\Piwigo\Db\DbConnection::build())->getRepository(\Piwigo\Users\UserInfoEntity::class), $this->currentUser);
            $preferencesService->updateParam('gallery_search_filters', array_keys(is_array($rulesFields) ? $rulesFields : []));
        }

        $url = $urlService->makeIndexUrl([
            'section' => 'search',
            'search' => $searchUuid,
        ]);

        return [$searchUuid, $url];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSearchResults(int|string $searchId, ?bool $superOrderBy, string $imagesWhere = ''): array
    {
        $search = $this->getSearchArray($searchId);
        if ($search === false) {
            $this->htmlRenderer->badRequest($this->redirectService, 'this search identifier does not exist');
        }

        if (! isset($search['q']) || ! is_string($search['q'])) {
            return $this->getRegularSearchResults($search, $imagesWhere);
        }

        return $this->getQuickSearchResults($search['q'], [
            'super_order_by' => $superOrderBy,
            'images_where' => $imagesWhere,
        ]);
    }
}
