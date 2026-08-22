<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Doctrine\DBAL\ArrayParameterType;
use Exception;
use LogicException;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Cache\SearchResultsCachePool;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Event\RenderCategoryName;
use Piwigo\Common\Enum\Section;
use Piwigo\Common\ValueObject\PhotoSortOrder;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Env;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\LikePattern;
use Piwigo\Db\SortRenderer;
use Piwigo\Event\Search\QsearchGetScopes;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\Event\QsearchExpressionParsed;
use Piwigo\Search\Event\QsearchGetImagesSqlScopes;
use Piwigo\Search\Event\QsearchPre;
use Piwigo\Search\Event\QsearchResults;
use Piwigo\Search\Inflector\InflectorInterface;
use Piwigo\Search\Projection\AllwordsRule;
use Piwigo\Search\Projection\AuthorRule;
use Piwigo\Search\Projection\CategoryRule;
use Piwigo\Search\Projection\DateRule;
use Piwigo\Search\Projection\ExpertRule;
use Piwigo\Search\Projection\Search;
use Piwigo\Search\Projection\SearchRules;
use Piwigo\Search\Projection\TagsRule;
use Piwigo\Session\SessionService;
use Piwigo\Tag\Event\RenderTagName;
use Piwigo\Tag\TagService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserService;
use RuntimeException;

/**
 * Search domain business logic.
 * `get_clause_for_filter()`/`get_items_for_filter()` -- entirely
 * `$page`-coupled (read `$page['search_details']`, written by this
 * service's own `getRegularSearchResults()` return value stored back into
 * `$page` by `Section\SectionPopulator`), zero DB access of their own --
 * are private methods on their single real caller,
 * `Search\SearchFilterRenderer`, instead of on this class.
 *
 * [SEC-18] The 3 `addslashes()` sites (REGEXP/FULLTEXT/LIKE clause
 * construction in the quick-search token evaluator) use real `?`-bound
 * parameters -- {@see qsearchGetTextTokenSearchSql()}'s own docblock
 * confirms this crosses the wire as-is, with no SQL string-literal
 * escaping step to compensate for. `quote()` itself is still real,
 * driver-safe infrastructure for a caller that genuinely can't compose
 * a `?`-bound parameter (e.g. a value that must be embedded as a SQL
 * literal, not passed positionally) -- {@see \Piwigo\Search\QsearchClause}'s
 * own docblock.
 *
 * Every `mixed` below stays that way by design: $search/$field/
 * $allwordsField (and every advanced-search-criterion param derived from
 * them) trace back to Search Projection's own already-documented JSON
 * rules bag; every `list<mixed> $params` matches SearchRepository's own
 * DBAL-bound-parameter rationale.
 */
final readonly class SearchService
{
    public function __construct(
        private AccessLevelChecker $accessLevelChecker,
        private SearchRepository $repo,
        private PermissionService $permissionService,
        private CategoryService $categoryService,
        private HtmlRenderingInterface $htmlRenderer,
        private RedirectServiceInterface $redirectService,
        private SessionService $sessionService,
        private EventDispatcher $eventDispatcher,
        private CurrentUser $currentUser,
        private readonly CurrentConfig $currentConfig,
        private SortRenderer $sortRenderer,
        private TagService $tagService,
        private ImageService $imageService,
        private UserService $userService,
        private PreferencesService $preferencesService,
        private SearchResultsCachePool $searchResultsCachePool,
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

        return match ($clausePattern) {
            'search_uuid = ?' => $this->repo->findSavedSearchByUuid((string) $candidate),
            'id = ?' => $this->repo->findSavedSearchById((int) $candidate),
            default => null,
        };
    }

    /**
     * Same as getSearchInfo(), plus request-context validation: dies on a
     * malformed candidate, refuses to resolve an old-style numeric-only
     * id once the search row already has a search_uuid (spies shouldn't
     * be able to walk index.php?/search/123, .../124, ...), and hands the
     * resolved id back through $resolvedSearchId for HistoryService::
     * logVisit() to read later, when rendering the "search" section.
     *
     * $section and $resolvedSearchId are explicit params. This method's
     * two real callers are SearchService::getValidatedSearchArray()
     * (reached from SearchFilterRenderer::render(), which passes
     * SectionContext::section, always available there, and returns the
     * resolved id up its own call chain to GalleryController) and
     * `Controller\Api\Images\ImageFilteredSearchCreateController` (never
     * runs SectionPopulator, passes null section and no out-param --
     * `$page['section']` is never 'search' for that caller either, so
     * nothing is written for it).
     *
     * @param int|null $resolvedSearchId in/out; set to the resolved
     *   search id when $section === 'search', left untouched otherwise
     */
    public function getValidatedSearchInfo(int|string $candidate, ?Section $section, ?int &$resolvedSearchId = null): ?Search
    {
        $clausePattern = self::getSearchIdPattern($candidate);
        if ($clausePattern === null) {
            $this->htmlRenderer->fatalError('Invalid search identifier');
        }

        $search = $this->getSearchInfo($candidate);

        if ($search instanceof Search) {
            if ($clausePattern === 'id = ?' and $search->searchUuid !== null) {
                $this->htmlRenderer->fatalError('this search is not reachable with its id, need the search_uuid instead');
            }

            if ($section === Section::Search) {
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
        if (! $search instanceof Search) {
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
     * own internal callers) and bad_request()s when nothing was found.
     *
     * @param int|null $resolvedSearchId in/out, see getValidatedSearchInfo()
     * @return array<string, mixed>|false
     */
    public function getValidatedSearchArray(int|string $searchId, ?Section $section, ?int &$resolvedSearchId = null): array|false
    {
        $search = $this->getValidatedSearchInfo($searchId, $section, $resolvedSearchId);
        if (! $search instanceof Search) {
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
    public function getRegularSearchResults(array $search, ?SqlCondition $imagesWhere = null): array
    {
        $imagesWhere ??= SqlCondition::fromRawSql('');

        $hasFiltersFilled = false;
        $matchingCatIds = null;
        $matchingTagIds = null;

        $forbidden = $this->forbiddenCondition();

        /** @var array<string, list<int>> $imageIdsForFilter */
        $imageIdsForFilter = [];

        $rawFiltersViews = $this->currentConfig->filtersViews->filters ?? $this->currentConfig->defaultFiltersViews;

        // $displayFilters stays a local raw array, not the VO map itself --
        // the loop below overwrites each entry's 'access' with a computed
        // bool (can *this* user actually use the filter), a different type
        // than FilterViewDefinition::$access (the access-level name), so it
        // can't be written back into the readonly VO.
        $displayFilters = [];
        foreach ($rawFiltersViews as $filtName => $filtConf) {
            $displayFilters[$filtName] = $filtConf->toArray();
        }

        foreach ($displayFilters as $filtName => $filtConf) {
            $filtConf['access'] = $filtConf['access'] === 'everybody'
                || ($filtConf['access'] === 'admins-only' && $this->accessLevelChecker->isAdmin())
                || ($filtConf['access'] === 'registered-users' && $this->accessLevelChecker->isClassicUser());
            $displayFilters[$filtName] = $filtConf;
        }

        $rawSearchFields = $search['fields'] ?? null;
        $rules = SearchRules::fromArray(is_array($rawSearchFields) ? array_filter($rawSearchFields, is_string(...), ARRAY_FILTER_USE_KEY) : []);

        // expert
        $expertRule = $rules->expert;
        $expertString = $expertRule instanceof ExpertRule ? $expertRule->string : null;
        if ($expertRule instanceof ExpertRule && $expertString !== null && $expertString !== '' && ($displayFilters['expert']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $expertItems = $this->getQuickSearchResults($expertString, [])['items'];
            $imageIdsForFilter['expert'] = is_array($expertItems) ? array_values(array_map(intval(...), array_filter($expertItems, is_numeric(...)))) : [];
        }

        // allwords
        $allwordsRule = $rules->allwords;
        if ($allwordsRule instanceof AllwordsRule && $allwordsRule->words !== [] && $allwordsRule->fields !== [] && ($displayFilters['words']['access'] ?? false)) {
            $hasFiltersFilled = true;
            [$imageIdsForFilter['allwords'], $matchingCatIds, $matchingTagIds] = $this->searchAllwords($allwordsRule, $forbidden);
        }

        // author
        $authorRule = $rules->author;
        $authorWords = $authorRule instanceof AuthorRule ? $authorRule->words : [];
        if ($authorRule instanceof AuthorRule && $authorWords !== [] && ($displayFilters['author']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $imageIdsForFilter['author'] = $this->queryImageIdsFor(
                SqlCondition::fromRawSql('i.author IN (:authorWords)', [
                    'authorWords' => $authorWords,
                ], [
                    'authorWords' => ArrayParameterType::STRING,
                ]),
                $forbidden
            );
        }

        // filetypes
        $filetypes = $rules->filetypes ?? [];
        if ($filetypes !== [] && ($displayFilters['file_type']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $clauses = [];
            $params = [];
            foreach ($filetypes as $i => $ext) {
                $clauses[] = "i.path LIKE :filetype{$i}";
                $params["filetype{$i}"] = '%.' . $ext;
            }

            $imageIdsForFilter['filetypes'] = $this->queryImageIdsFor(SqlCondition::fromRawSql('(' . implode(' OR ', $clauses) . ')', $params), $forbidden);
        }

        // added_by
        $addedByIds = array_values(array_map(intval(...), array_filter($rules->addedBy ?? [], is_numeric(...))));
        if ($addedByIds !== [] && ($displayFilters['added_by']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $imageIdsForFilter['added_by'] = $this->queryImageIdsFor(
                SqlCondition::fromRawSql('i.addedByUser IN (:addedByIds)', [
                    'addedByIds' => $addedByIds,
                ], [
                    'addedByIds' => ArrayParameterType::INTEGER,
                ]),
                $forbidden
            );
        }

        // cat
        $catRule = $rules->cat;
        $catWords = [];
        if ($catRule instanceof CategoryRule) {
            foreach ($catRule->words as $catWord) {
                if (is_numeric($catWord)) {
                    $catWords[] = (int) $catWord;
                }
            }
        }
        if ($catRule instanceof CategoryRule && $catWords !== [] && ($displayFilters['album']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $catIds = $catRule->subInc
                ? $this->categoryService->getSubcatIds($catWords)
                : $catWords;

            if ($catIds !== []) {
                $imageIdsForFilter['cat'] = $this->queryImageIdsFor(
                    SqlCondition::fromRawSql('ic.category IN (:catIds)', [
                        'catIds' => $catIds,
                    ], [
                        'catIds' => ArrayParameterType::INTEGER,
                    ]),
                    $forbidden
                );
            }
        }

        // date_posted
        $datePostedRule = $rules->datePosted;
        $datePostedPreset = $datePostedRule instanceof DateRule ? $datePostedRule->preset : null;
        if ($datePostedPreset !== null && $datePostedPreset !== '' && ($displayFilters['post_date']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $condition = $this->dateFilterClause('i.dateAvailable', $datePostedPreset, $datePostedRule, [
                '24h' => [24, 'HOUR'],
                '7d' => [7, 'DAY'],
                '30d' => [30, 'DAY'],
                '3m' => [3, 'MONTH'],
                '6m' => [6, 'MONTH'],
            ]);
            $imageIdsForFilter['date_posted'] = $this->queryImageIdsFor($condition, $forbidden);
        }

        // date_created
        $dateCreatedRule = $rules->dateCreated;
        $dateCreatedPreset = $dateCreatedRule instanceof DateRule ? $dateCreatedRule->preset : null;
        if ($dateCreatedPreset !== null && $dateCreatedPreset !== '' && ($displayFilters['creation_date']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $condition = $this->dateFilterClause('i.dateCreation', $dateCreatedPreset, $dateCreatedRule, [
                '7d' => [7, 'DAY'],
                '30d' => [30, 'DAY'],
                '3m' => [3, 'MONTH'],
                '6m' => [6, 'MONTH'],
                '12m' => [12, 'MONTH'],
            ]);
            $imageIdsForFilter['date_created'] = $this->queryImageIdsFor($condition, $forbidden);
        }

        // ratios
        $ratios = $rules->ratios ?? [];
        if ($ratios !== [] && ($displayFilters['ratio']['access'] ?? false)) {
            $hasFiltersFilled = true;
            // `i.width`/`i.height` are both plain integer columns. MySQL's
            // `/` operator always computes in DECIMAL/floating context
            // regardless of operand types, but PostgreSQL's `/` on two
            // `integer` operands TRUNCATES to an integer (e.g. `200/150`
            // is `1` on Postgres, `1.333...` on MySQL) -- a genuine 1.33
            // (Landscape) ratio would otherwise misclassify as `square`
            // (`1 >= 0.95 AND 1 <= 1.05`). `i.width * 1.0` forces
            // decimal-context arithmetic on both platforms (a
            // DECIMAL/numeric literal operand promotes the whole
            // expression) without needing a DQL CAST -- DQL has none
            // built in.
            //
            // A genuinely zero i.height makes this division a literal
            // divide-by-zero: MySQL's `/` silently returns NULL for that,
            // while Postgres raises a "division by zero" DriverException,
            // 500ing the whole request. NULLIF(i.height, 0) forces the
            // divisor to a SQL NULL instead of a literal 0 for that row,
            // so the whole expression evaluates to NULL (matching MySQL's
            // own NULL-on-zero-divisor behavior) rather than erroring --
            // a NULL comparison is simply false for every bucket, so a
            // zero-height row still correctly falls through unclassified
            // either way.
            $clauseForRatio = [
                'Portrait' => 'i.width * 1.0 / NULLIF(i.height, 0) < 0.95',
                // Not `BETWEEN` -- a real DQL grammar limitation found
                // empirically: SimpleConditionalExpression()'s own
                // lookahead dispatch only walks past a *simple* path
                // expression (`i.width`) to decide whether what follows is
                // a BETWEEN/LIKE/IN clause, not past a full arithmetic
                // expression (`i.width / i.height`) -- so a BETWEEN
                // immediately after a division LHS mis-dispatches into
                // ComparisonExpression() instead, which then chokes
                // expecting a `=`/`<`/etc operator and finds `BETWEEN`.
                // Plain comparison operators on the exact same division
                // LHS parse fine (proven by the 3 buckets below), so this
                // expresses the identical inclusive range via two ANDed
                // comparisons instead (BETWEEN's own definition).
                'square' => '(i.width * 1.0 / NULLIF(i.height, 0) >= 0.95 AND i.width * 1.0 / NULLIF(i.height, 0) <= 1.05)',
                'Landscape' => '(i.width * 1.0 / NULLIF(i.height, 0) > 1.05 AND i.width * 1.0 / NULLIF(i.height, 0) < 2)',
                'Panorama' => 'i.width * 1.0 / NULLIF(i.height, 0) >= 2',
            ];
            $clauses = [];
            foreach ($ratios as $r) {
                if (isset($clauseForRatio[$r])) {
                    $clauses[] = $clauseForRatio[$r];
                }
            }

            if ($clauses !== []) {
                $imageIdsForFilter['ratios'] = $this->queryImageIdsFor(SqlCondition::fromRawSql('(' . implode(' OR ', $clauses) . ')'), $forbidden);
            }
        }

        // ratings
        $ratings = $rules->ratings ?? [];
        if ($this->currentConfig->rateEnabled && $ratings !== [] && ($displayFilters['rating']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $clauses = [];
            $ratingParams = [];
            foreach ($ratings as $i => $r) {
                if ((int) $r === 0) {
                    $clauses[] = 'i.ratingScore IS NULL';
                } else {
                    $clauses[] = "(i.ratingScore >= :ratingMin{$i} AND i.ratingScore < :ratingMax{$i})";
                    $ratingParams["ratingMin{$i}"] = (int) $r - 1;
                    $ratingParams["ratingMax{$i}"] = (int) $r;
                }
            }

            $imageIdsForFilter['ratings'] = $this->queryImageIdsFor(SqlCondition::fromRawSql('(' . implode(' OR ', $clauses) . ')', $ratingParams), $forbidden);
        }

        // filesize
        $filesizeMinRaw = $rules->filesizeMin;
        $filesizeMaxRaw = $rules->filesizeMax;
        if ($filesizeMinRaw !== null && $filesizeMinRaw !== 0 && $filesizeMaxRaw !== null && $filesizeMaxRaw !== 0 && is_numeric($filesizeMinRaw) && is_numeric($filesizeMaxRaw) && ($displayFilters['file_size']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $imageIdsForFilter['filesize'] = $this->queryImageIdsFor(
                SqlCondition::fromRawSql('i.filesize BETWEEN :filesizeMin AND :filesizeMax', [
                    'filesizeMin' => (float) $filesizeMinRaw - 100.0,
                    'filesizeMax' => (float) $filesizeMaxRaw + 100.0,
                ]),
                $forbidden
            );
        }

        // height
        $heightMinRaw = $rules->heightMin;
        $heightMaxRaw = $rules->heightMax;
        if ($heightMinRaw !== null && $heightMinRaw !== 0 && $heightMaxRaw !== null && $heightMaxRaw !== 0 && ($displayFilters['height']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $imageIdsForFilter['height'] = $this->queryImageIdsFor(
                SqlCondition::fromRawSql('i.height BETWEEN :heightMin AND :heightMax', [
                    'heightMin' => $heightMinRaw,
                    'heightMax' => $heightMaxRaw,
                ]),
                $forbidden
            );
        }

        // width
        $widthMinRaw = $rules->widthMin;
        $widthMaxRaw = $rules->widthMax;
        if ($widthMinRaw !== null && $widthMinRaw !== 0 && $widthMaxRaw !== null && $widthMaxRaw !== 0 && ($displayFilters['width']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $imageIdsForFilter['width'] = $this->queryImageIdsFor(
                SqlCondition::fromRawSql('i.width BETWEEN :widthMin AND :widthMax', [
                    'widthMin' => $widthMinRaw,
                    'widthMax' => $widthMaxRaw,
                ]),
                $forbidden
            );
        }

        // tags
        $tagsRule = $rules->tags;
        $tagsWords = [];
        if ($tagsRule instanceof TagsRule) {
            foreach ($tagsRule->words as $tagWord) {
                if (is_numeric($tagWord)) {
                    $tagsWords[] = (int) $tagWord;
                }
            }
        }
        $tagsMode = $tagsRule instanceof TagsRule ? $tagsRule->mode : 'AND';
        if ($tagsRule instanceof TagsRule && $tagsWords !== [] && ($displayFilters['tags']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $imageIdsForFilter['tags'] = array_values(array_map(intval(...), array_filter($this->tagService->getImageIdsForTags(array_map(TagId::from(...), $tagsWords), $tagsMode), is_numeric(...))));
        }

        // custom search
        if (! $imagesWhere->isEmpty()) {
            $imageIdsForFilter['custom'] = $this->queryImageIdsFor($imagesWhere, $forbidden);
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
            $orderBySqlBody = $this->sortRenderer->toSqlBody($this->currentConfig->orderBy);
            $items = $this->repo->findImageIdsByRawWhere('id IN (' . implode(',', array_fill(0, count($items), '?')) . ')', $items, $orderBySqlBody);
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
     * SearchRepository's own quicksearch-facing executors are
     * positional-`?`-only (its own "generic parameterized executor"
     * design, see that class's docblock), so a {@see PermissionCriteria}
     * fragment's own named-placeholder SqlCondition is rewritten to
     * positional `?`s here, same manual per-element expansion convention
     * this file's own IN-clause callers already use for their own array
     * params (e.g. `implode(',', array_fill(0, count($x), '?'))`). Bare
     * fragment, no prefix -- callers that need a leading " AND " add it
     * themselves.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function positionalCondition(SqlCondition $condition): array
    {
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
        }, (string) $condition->expr);
        if ($sql === null) {
            throw new RuntimeException('positionalCondition(): preg_replace_callback() failed');
        }

        return [$sql, array_values($values)];
    }

    /**
     * `PermissionCriteria`'s own 5 `*Condition(string): SqlCondition`
     * methods work unchanged against a DQL `QueryBuilder` -- a caller
     * just passes a DQL property path (`ic.category`) instead of a raw
     * column name.
     * {@see positionalCondition()} itself stays -- the quicksearch token
     * evaluator's own `getQuickSearchResultsNoCache()` still builds its
     * permission fragment through it, a genuine, permanent DBAL-only
     * caller (see `SearchRepository`'s class docblock). Public: `render()`'s
     * own `$page['search_details']['forbidden']` (built once per request,
     * consumed throughout `SearchFilterRenderer`'s filter-sidebar blocks)
     * needs the exact same combined condition -- reused here instead of
     * duplicating the same 4-call `PermissionCriteria` combination twice.
     */
    public function forbiddenCondition(): SqlCondition
    {
        $criteria = $this->permissionService->getPermissionCriteria();

        // visible_images's own old fallthrough into forbidden_images
        // (fieldName 'id' -> the images-table's own level check) -- see
        // PermissionCriteria's own docblock.
        return SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('ic.category'),
            $criteria->visibleCategoriesCondition('ic.category'),
            $criteria->visibleImagesCondition('i.id'),
            $criteria->maxLevelCondition('i.level'),
        );
    }

    /**
     * Shared "images matching this WHERE fragment, filtered by the current
     * user's permissions" executor for every advanced-search criterion --
     * all 12 share the exact same `FROM ImageEntity i INNER JOIN
     * i.imageCategories ic WHERE <criterion>
     * <forbidden>` shape. $criterion is AND-combined with $forbidden
     * unparenthesized -- every real criterion built above already wraps
     * its own internal OR-joined clauses in parens itself when it has any,
     * same convention
     * {@see \Piwigo\Category\CategoryRepository::findIdsAndImageOrderWithConditions()}
     * already established.
     *
     * @return list<int>
     */
    private function queryImageIdsFor(SqlCondition $criterion, SqlCondition $forbidden): array
    {
        return $this->repo->findImageIdsMatching(SqlCondition::combine('AND', $criterion, $forbidden));
    }

    /**
     * date_posted/date_created share this exact preset-or-custom-range
     * clause-building logic, differing only in the target DQL property
     * path and the preset options list.
     *
     * The preset branch uses DQL's own real `DATE_SUB()`/
     * `CURRENT_TIMESTAMP()` built-ins (unlike `YEAR()`/`MONTH()` -- see
     * the Calendar redesign's own `Db\DqlFunction\` classes for that
     * distinction), not a PHP `Env::now()`-computed threshold: this
     * filter's own real semantics ("posted in the last 24h") are
     * genuinely relative to the DB server's real wall clock, deliberately
     * NOT `PIWIGO_TEST_NOW`-frozen. $presetOptions carries a `[int
     * $amount, string $unit]` tuple (`$unit` one of `DATE_SUB()`'s own
     * accepted set: `HOUR`/`DAY`/`MONTH`) instead of raw MySQL INTERVAL
     * syntax.
     *
     * @param  array<string, array{0: int, 1: string}>  $presetOptions
     */
    private function dateFilterClause(string $dqlColumn, string $preset, ?DateRule $field, array $presetOptions): SqlCondition
    {
        if (isset($presetOptions[$preset])) {
            [$amount, $unit] = $presetOptions[$preset];

            return SqlCondition::fromRawSql(
                $dqlColumn . " > DATE_SUB(CURRENT_TIMESTAMP(), :dateAmount, '{$unit}')",
                [
                    'dateAmount' => $amount,
                ]
            );
        }

        $custom = $field instanceof DateRule ? $field->custom : [];

        if ($preset === 'custom' && $custom !== []) {
            $subconditions = [];
            // $customDates (a flip, kept only for its isset() lookups
            // below) canonicalizes a purely-numeric string value (e.g.
            // (string) 20250101) into an int array key -- array_keys() on
            // it would hand the loop below a genuine int, not the string
            // substr() requires. Deduplicating the
            // iteration list separately via array_unique() (which, unlike
            // array_flip()'s keys, never touches value types) sidesteps
            // that entirely.
            $customDates = array_flip($custom);

            foreach (array_unique($custom) as $i => $customDate) {
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
                    $subconditions[] = SqlCondition::fromRawSql(
                        "({$dqlColumn} BETWEEN :dateBegin{$i} AND :dateEnd{$i})",
                        [
                            "dateBegin{$i}" => $begin,
                            "dateEnd{$i}" => $end,
                        ]
                    );
                }
            }

            $combined = SqlCondition::combine('OR', ...$subconditions);

            return $combined->isEmpty() ? $combined : SqlCondition::fromRawSql('(' . (string) $combined->expr . ')', $combined->parameters, $combined->types);
        }

        // No preset and no custom range: nothing to filter on. An empty
        // condition says exactly that, and every consumer combines it before
        // use, so it disappears rather than contributing a tautology --
        // matching the empty condition the custom-range branch above already
        // returns when it finds no bounds.
        return SqlCondition::fromRawSql('');
    }

    /**
     * Converted to DQL, same shape as every other advanced-search
     * criterion -- `$dqlFieldsByColumn` maps the 4 known searchable
     * `ImageEntity` columns; each word's own field-clauses stay OR-joined
     * and parenthesized. The whole word-clause group is wrapped in an
     * *outer* pair of parens before it's AND-combined with $forbidden --
     * without those outer parens, an 'OR' $allwordsMode with 2+ words
     * would let $forbidden's own permission restriction bind only to the
     * last OR-branch instead of the whole clause, same class of fix
     * dateFilterClause()'s own custom-range branch also needs its outer
     * parens for.
     *
     * @return array{0: list<int>, 1: ?list<int>, 2: ?list<int>}
     */
    private function searchAllwords(AllwordsRule $allwordsRule, SqlCondition $forbidden): array
    {
        $fields = array_intersect(['file', 'name', 'comment', 'author'], $allwordsRule->fields);
        $dqlFieldsByColumn = [
            'file' => 'i.file',
            'name' => 'i.name',
            'comment' => 'i.comment',
            'author' => 'i.author',
        ];

        $catFieldsDictionary = [
            'cat-title' => 'name',
            'cat-desc' => 'comment',
        ];
        $catFields = array_intersect(array_keys($catFieldsDictionary), $allwordsRule->fields);

        $wordConditions = [];
        $catIdsByWord = [];
        $tagIdsByWord = [];

        // The category-name/comment and tag-name lookups below, plus the
        // image lookups by matching category/tag ids, go through
        // CategoryService/TagService (which own those tables) instead of
        // SearchRepository's own raw-DBAL image lookups -- cross-domain
        // sub-lookups, not a genuine search-domain query shape.
        $searchesTags = in_array('tags', $allwordsRule->fields, true);
        $tagService = $searchesTags ? $this->tagService : null;

        foreach ($allwordsRule->words as $wordIndex => $word) {
            $fieldClauses = [];
            $params = [];
            $types = [];
            foreach ($fields as $field) {
                $paramName = "word{$wordIndex}_{$field}";
                $fieldClauses[] = $dqlFieldsByColumn[$field] . ' LIKE :' . $paramName;
                $params[$paramName] = LikePattern::containing($word);
            }

            if ($catFields !== []) {
                $catIds = $this->categoryService->getIdsByNameOrCommentLike(
                    LikePattern::containing($word),
                    in_array('cat-title', $catFields, true),
                    in_array('cat-desc', $catFields, true)
                );
                $catIdsByWord[$word] = $catIds;
                if ($catIds !== []) {
                    $catImageIds = $this->categoryService->getDistinctLinkedImageIds($catIds);
                    if ($catImageIds !== []) {
                        $paramName = "word{$wordIndex}_catImages";
                        $fieldClauses[] = "i.id IN (:{$paramName})";
                        $params[$paramName] = $catImageIds;
                        $types[$paramName] = ArrayParameterType::INTEGER;
                    }
                }
            }

            if ($searchesTags) {
                assert($tagService instanceof TagService);
                $tagIds = $tagService->getIdsByNameLike('%' . $word . '%');
                $tagIdsByWord[$word] = $tagIds;
                if ($tagIds !== []) {
                    $tagImageIds = $tagService->getImageIdsForTagIds(array_map(TagId::from(...), $tagIds));
                    if ($tagImageIds !== []) {
                        $paramName = "word{$wordIndex}_tagImages";
                        $fieldClauses[] = "i.id IN (:{$paramName})";
                        $params[$paramName] = $tagImageIds;
                        $types[$paramName] = ArrayParameterType::INTEGER;
                    }
                }
            }

            if ($fieldClauses !== []) {
                $wordConditions[] = SqlCondition::fromRawSql('(' . implode(' OR ', $fieldClauses) . ')', $params, $types);
            }
        }

        $combined = SqlCondition::combine($allwordsRule->mode, ...$wordConditions);
        $filterCondition = $combined->isEmpty() ? $combined : SqlCondition::fromRawSql('(' . (string) $combined->expr . ')', $combined->parameters, $combined->types);

        $imageIds = $this->repo->findImageIdsMatching(SqlCondition::combine('AND', $filterCondition, $forbidden));

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
     * Free-text search terms are bound as real `?` parameters, never
     * manually quote()'d and spliced inline. $values is positional, in
     * the exact order its corresponding `?` appears across $clauses --
     * callers thread it straight through to whichever of
     * SearchRepository's positional-`?` executors they already use.
     *
     * $ftsTable names the SQLite FTS5 virtual table to `MATCH` against
     * (`categories_fts`/`images_fts`/`images_fts_author`/`tags_fts`, see
     * Version20260804122300::addSqliteFts()) -- unlike Postgres's
     * `tsv_search`/`tsv_author` generated columns (which live ON the same
     * base table already in the caller's own `FROM` clause, so no extra
     * name is needed) or MySQL's `MATCH(cols)` (same reasoning), SQLite's
     * FTS index is a genuinely separate table this method has no other way
     * to know: two different callers pass the identical $fields shape
     * (`['name', 'comment']` for both `images` and `categories`), so
     * $fields alone can't disambiguate it. Required (not defaulted) so
     * every caller states explicitly which table it means, even on
     * platforms that ignore it -- a silently-wrong default here would only
     * surface as a real search returning zero rows on SQLite specifically.
     *
     * @param  string[]  $fields
     * @return array{0: list<non-falsy-string>, 1: list<string>}
     */
    public function qsearchGetTextTokenSearchSql(QSingleToken $token, array $fields, string $ftsTable): array
    {
        // Neither REGEXP nor MATCH()/AGAINST() has a Postgres equivalent --
        // ~* (POSIX case-INSENSITIVE regex match, needs \y not \b for a
        // real word boundary -- 'the cat sat' ~* '\bcat\b' is false, ~*
        // '\ycat\y' is true) and a tsquery match against the real
        // per-table tsv_search/tsv_author generated column (see
        // toTsqueryTerm()'s own docblock) are the real ones. ~*
        // specifically, not ~ (case-SENSITIVE) -- every real $fields
        // column here (name/comment/author) inherits its table's default
        // utf8mb4_unicode_ci collation with no per-column override, and
        // MySQL's REGEXP case-sensitivity follows the operand's
        // collation, so REGEXP against these columns is already
        // case-insensitive today (both 'Mountain View' REGEXP
        // '\\bmountain\\b' and 'mountain view' REGEXP '\\bMountain\\b'
        // match) -- a bare ~ would silently make search case-sensitive
        // only on Postgres, a real behavior regression, not a
        // portability wash. $fields' own shape ({'name','comment'} on
        // categories/images, {'name'} on tags, {'author'} on images)
        // deterministically picks the tsvector column: every shape
        // except {'author'} maps to tsv_search, matching how each
        // migration paired that generated column with exactly the same
        // field combination.
        $isPostgres = $this->repo->isPostgres();
        $isSqlite = $this->repo->isSqlite();

        $clauses = [];
        $values = [];
        $variants = array_merge([$token->term], $token->variants);
        $fts = [];
        $ftVariants = [];
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
                    throw new Exception('qsearchGetTextTokenSearchSql(): preg_split() failed');
                }

                $max = max(array_map(mb_strlen(...), $parts));
                if ($max < 4) {
                    $useFt = false;
                }
            }

            if (! $useFt) {
                if ($isPostgres) {
                    $pre = ((bool) ($token->modifier & QSingleToken::QST_WILDCARD_BEGIN)) ? '' : '\\y';
                    $post = ((bool) ($token->modifier & QSingleToken::QST_WILDCARD_END)) ? '' : '\\y';
                    foreach ($fields as $field) {
                        $clauses[] = $field . ' ~* ?';
                        $values[] = $pre . preg_quote($variant) . $post;
                    }

                    continue;
                }

                if ($isSqlite) {
                    // DbConnection::initSqliteConnection() registers this
                    // REGEXP UDF as a thin preg_match() wrapper -- real PHP
                    // PCRE, so \b is always a real word boundary here, no
                    // MySQL-style engine-version detection needed at all.
                    $pre = ((bool) ($token->modifier & QSingleToken::QST_WILDCARD_BEGIN)) ? '' : '\\b';
                    $post = ((bool) ($token->modifier & QSingleToken::QST_WILDCARD_END)) ? '' : '\\b';
                    foreach ($fields as $field) {
                        $clauses[] = $field . ' REGEXP ?';
                        $values[] = $pre . preg_quote($variant) . $post;
                    }

                    continue;
                }

                // getDbVersion() is a property read on the already-connected
                // driver handle, not a query -- no memoization needed.
                $dbVersion = $this->repo->getDbVersion();
                $useRegexpICU = preg_match('/mariadb/i', $dbVersion) !== 1 && version_compare($dbVersion, '8.0.4', '>');

                // A single literal backslash here ('\\b' is a 2-char PHP
                // string: backslash + b) is exactly what REGEXP needs to
                // see -- a bound parameter crosses the wire as-is, with no
                // SQL string-literal escaping step to compensate for.
                $pre = ((bool) ($token->modifier & QSingleToken::QST_WILDCARD_BEGIN)) ? '' : ($useRegexpICU ? '\\b' : '[[:<:]]');
                $post = ((bool) ($token->modifier & QSingleToken::QST_WILDCARD_END)) ? '' : ($useRegexpICU ? '\\b' : '[[:>:]]');
                foreach ($fields as $field) {
                    $clauses[] = $field . ' REGEXP ?';
                    $values[] = $pre . preg_quote($variant) . $post;
                }
            } elseif ($isPostgres) {
                $ftPostgres = self::toTsqueryTerm($variant, (bool) ($token->modifier & QSingleToken::QST_WILDCARD_END));
                if ($ftPostgres !== null) {
                    $fts[] = $ftPostgres;
                }
            } elseif ($isSqlite) {
                // Always a quoted FTS5 phrase, not gated on QST_QUOTED the
                // way the MySQL branch below is -- verified live (see
                // Version20260804122300::addSqliteFts()'s own docblock)
                // that quoting a single-word variant is a no-op (identical
                // result to unquoted) while quoting a real multi-word
                // phrase is what forces the contiguous, substring-adjacent
                // match this method's callers actually want, so there is
                // no case where leaving it unquoted would be correct.
                $ft = '"' . str_replace('"', '""', $variant) . '"';
                if ((bool) ($token->modifier & QSingleToken::QST_WILDCARD_END)) {
                    $ft .= '*';
                }

                $fts[] = $ft;
                $ftVariants[] = $variant;
            } else {
                $ft = $variant;
                if ((bool) ($token->modifier & QSingleToken::QST_QUOTED)) {
                    $ft = '"' . $ft . '"';
                }

                if ((bool) ($token->modifier & QSingleToken::QST_WILDCARD_END)) {
                    $ft .= '*';
                }

                $fts[] = $ft;
                $ftVariants[] = $variant;
            }
        }

        if ($fts !== []) {
            if ($isPostgres) {
                $tsvColumn = $fields === ['author'] ? 'tsv_author' : 'tsv_search';
                $clauses[] = $tsvColumn . " @@ to_tsquery('simple', ?)";
                // Variants of the *same* token (spelling/accent-folded
                // alternatives) are a "match either" set, same as the
                // MySQL branch's own implode(' ', $fts) below (its IN
                // BOOLEAN MODE default, unprefixed-term combining is a
                // should-match/OR-like ranking, not a strict AND) -- |
                // (tsquery OR) is the direct equivalent. <-> (used inside
                // each $fts entry for a multi-word phrase, see
                // toTsqueryTerm()) binds tighter than | in tsquery's own
                // operator precedence, so no extra parentheses are needed
                // to keep a phrase's words grouped together.
                $values[] = implode(' | ', $fts);
            } elseif ($isSqlite) {
                // id IN (SELECT rowid FROM $ftsTable WHERE ... MATCH ...)
                // rather than a bare "$ftsTable MATCH ?" clause -- $ftsTable
                // is a genuinely separate virtual table (see $ftsTable's own
                // docblock above), not a column on the row this clause's
                // sibling REGEXP/LIKE fragments already scan, so it needs an
                // explicit correlation back to that row via id/rowid, unlike
                // MySQL's MATCH(cols) or Postgres's tsv_search column
                // (both already columns of the same row). The LIKE
                // confirmation mirrors the MySQL branch's own reasoning
                // below for defense in depth, even though the trigram
                // tokenizer's own phrase-adjacency semantics (verified live,
                // see addSqliteFts()'s docblock) don't reproduce that same
                // false-positive class in the first place.
                $wildcardEnd = (bool) ($token->modifier & QSingleToken::QST_WILDCARD_END);
                $likeClauses = [];
                foreach ($fields as $field) {
                    foreach ($ftVariants as $ftVariant) {
                        $likeClauses[] = $field . ' LIKE ?';
                        $values[] = $wildcardEnd
                            ? LikePattern::startingWith($ftVariant)
                            : LikePattern::containing($ftVariant);
                    }
                }

                $clauses[] = 'id IN (SELECT rowid FROM ' . $ftsTable . ' WHERE ' . $ftsTable . ' MATCH ?) AND (' . implode(' OR ', $likeClauses) . ')';
                array_splice($values, count($values) - count($likeClauses), 0, [implode(' OR ', $fts)]);
            } else {
                // WITH PARSER ngram (see Version20260804122300.php, chosen
                // for CJK support -- no word boundaries to split on)
                // indexes overlapping ngram_token_size=2 character
                // fragments rather than whole words, so MATCH() AGAINST()
                // alone can score a document positively via pure
                // coincidental fragment overlap with content that shares
                // none of the query's actual words -- confirmed live: a
                // widened Inflector variant ('families', searching
                // 'family') scored 0.09 relevance against a category
                // named "Nested Sub Album" purely because both strings
                // contain the 2-char fragment 'es' ('famil-i-ES' /
                // 'Nest-ED'... 'N-ES-ted'), with nothing else in common.
                // An exact-substring LIKE confirmation, ANDed with the
                // FULLTEXT clause, keeps FULLTEXT as the fast
                // index-backed candidate filter while eliminating this
                // ngram false-positive class -- LIKE is a literal byte/
                // character substring test, so this is not
                // English-specific and stays correct for the same CJK
                // content the ngram parser exists to serve.
                $wildcardEnd = (bool) ($token->modifier & QSingleToken::QST_WILDCARD_END);
                $likeClauses = [];
                foreach ($fields as $field) {
                    foreach ($ftVariants as $ftVariant) {
                        $likeClauses[] = $field . ' LIKE ?';
                        $values[] = $wildcardEnd
                            ? LikePattern::startingWith($ftVariant)
                            : LikePattern::containing($ftVariant);
                    }
                }

                $clauses[] = 'MATCH(' . implode(', ', $fields) . ') AGAINST(? IN BOOLEAN MODE) AND (' . implode(' OR ', $likeClauses) . ')';
                array_splice($values, count($values) - count($likeClauses), 0, [implode(' ', $fts)]);
            }
        }

        return [$clauses, $values];
    }

    /**
     * One tsquery-syntax fragment for a single search-token variant --
     * the Postgres counterpart to the MySQL branch's own $ft-building
     * (see this method's own caller). Quoted phrases and a trailing
     * wildcard are mutually exclusive by construction (the caller's own
     * $useFt-eligibility check above already forces that combination onto
     * the REGEXP fallback instead), so this never needs to combine a
     * phraseto_tsquery()-shaped adjacency with a :* prefix on the same
     * term.
     *
     * Re-splits $variant on the same punctuation charset the
     * FULLTEXT-eligibility check above already uses, plus whitespace --
     * to_tsquery()'s own argument is a query language, not plain text, so
     * passing a raw word containing one of its own operator characters
     * (&|!():<->) unescaped could throw a parse error or silently change
     * the query's meaning; splitting first and rejoining with only the
     * &/</-/> characters this method itself controls avoids that
     * entirely, with no bespoke escaper needed. Whitespace matters too:
     * the eligibility check's own punctuation-only split pattern leaves
     * a plain multi-word phrase like "blue sky" completely unsplit
     * (space isn't in that charset, and that check
     * only needs the longest delimited run's length, not real per-word
     * boundaries) -- passed straight through unsplit here too, a bare
     * "blue sky" would go to to_tsquery() as one un-operatored 2-word
     * string, which Postgres rejects outright ("syntax error in
     * tsquery"), not a query engine that just no-ops on suspect input. A
     * quoted multi-word phrase becomes phraseto_tsquery()-shaped word1
     * <-> word2 <-> ... adjacency (Postgres's own real phrase-match
     * operator); a trailing wildcard becomes term:* prefix syntax on the
     * last word.
     */
    private static function toTsqueryTerm(string $variant, bool $wildcardEnd): ?string
    {
        $words = preg_split(
            '/[\s' . preg_quote('-\'!"#$%&()*+,./:;<=>?@[\]^`{|}~', '/') . ']+/',
            $variant,
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        if ($words === false || $words === []) {
            return null;
        }

        if ($wildcardEnd) {
            $words[count($words) - 1] .= ':*';
        }

        return implode(' <-> ', $words);
    }

    /**
     * [SEC-18] The LIKE clause's free-text term is bound via a `?`
     * parameter -- `%`/`_` are still backslash-escaped manually first
     * (LIKE's own wildcard syntax, meaningful even inside a bound value,
     * not a SQL string-literal concern a bound parameter would already
     * handle).
     *
     * `CONVERT(file, CHAR)` is dropped for Postgres -- `images.file` is a
     * plain `varchar(255)` on both platforms (`COLLATE utf8mb4_bin`/
     * `COLLATE "C"`, both real byte-order/case-sensitive collations),
     * never a binary/blob type this cast would have a real purpose
     * against (kept as a defensive no-op on the MySQL side). Plain
     * `LIKE` is the correct case-SENSITIVE match on Postgres too (its
     * own default, unlike MySQL's collation-dependent default) -- the
     * column's real collation on both platforms is a case-sensitive
     * one, not the case-insensitive default this schema uses elsewhere,
     * so no `ILIKE` is needed.
     */
    public function qsearchGetImages(QExpression $expr, QResults $qsr): void
    {
        $qsr->images_iids = array_fill(0, count($expr->stokens), []);

        $isPostgres = $this->repo->isPostgres();
        $isSqlite = $this->repo->isSqlite();

        for ($i = 0; $i < count($expr->stokens); $i++) {
            $token = $expr->stokens[$i];
            $scope = $token->scope;
            $scopeId = $scope !== null ? $scope->id : 'photo';
            $clauses = [];
            $params = [];

            // CONVERT(file, CHAR) is a MySQL-only function -- would throw
            // "no such function: CONVERT" on SQLite, found while widening
            // this method's own $isPostgres check for Wave 2 (SQLite has
            // no equivalent need for it either: same "already a plain
            // string column, defensive no-op" reasoning as the Postgres
            // branch's own docblock above). Real, documented SQLite
            // divergence, not fixed here: `file`/`path`/`permalink` are
            // COLLATE BINARY for case-sensitive matching on MySQL/Postgres,
            // but SQLite's LIKE case-sensitivity is a single
            // connection-wide `case_sensitive_like` pragma, not a
            // per-column collation concern -- verified live, no per-column
            // fix is possible; flipping that pragma globally would instead
            // break every OTHER LIKE search in this app that currently
            // relies on SQLite's default case-insensitive matching to
            // parallel this schema's own `utf8mb4_unicode_ci` columns.
            $fileLike = ($isPostgres || $isSqlite) ? 'file LIKE ?' : 'CONVERT(file, CHAR) LIKE ?';
            $fileLikeValue = LikePattern::containing($token->term);

            switch ($scopeId) {
                case 'photo':
                    $clauses[] = $fileLike;
                    $params[] = $fileLikeValue;
                    [$textClauses, $textValues] = $this->qsearchGetTextTokenSearchSql($token, ['name', 'comment'], 'images_fts');
                    $clauses = array_merge($clauses, $textClauses);
                    $params = array_merge($params, $textValues);

                    break;

                case 'file':
                    $clauses[] = $fileLike;
                    $params[] = $fileLikeValue;

                    break;
                case 'author':
                    if ((bool) strlen($token->term)) {
                        [$textClauses, $textValues] = $this->qsearchGetTextTokenSearchSql($token, ['author'], 'images_fts_author');
                        $clauses = array_merge($clauses, $textClauses);
                        $params = array_merge($params, $textValues);
                    } elseif ((bool) ($token->modifier & QSingleToken::QST_WILDCARD)) {
                        $clauses[] = 'author IS NOT NULL';
                    } else {
                        $clauses[] = 'author IS NULL';
                    }

                    break;
                case 'width':
                case 'height':
                    assert($scope !== null);
                    $clauses[] = $scope->getSql($scopeId, $token);

                    break;
                case 'ratio':
                    assert($scope !== null);
                    // Same integer-division-truncation and divide-by-zero
                    // risk as getRegularSearchResults()'s own ratio
                    // buckets -- see that call site's docblock.
                    // `width*1.0` forces decimal-context arithmetic on
                    // both platforms; NULLIF(height, 0) guards a
                    // genuinely zero height the same way.
                    $clauses[] = $scope->getSql('width*1.0/NULLIF(height, 0)', $token);

                    break;
                case 'size':
                    assert($scope !== null);
                    $clauses[] = $scope->getSql('width*height', $token);

                    break;
                case 'hits':
                    assert($scope !== null);
                    $clauses[] = $scope->getSql('hit', $token);

                    break;
                case 'score':
                    assert($scope !== null);
                    $clauses[] = $scope->getSql('rating_score', $token);

                    break;
                case 'filesize':
                    assert($scope !== null);
                    $clauses[] = $scope->getSql('1024*filesize', $token);

                    break;
                case 'created':
                    assert($scope !== null);
                    $clauses[] = $scope->getSql('date_creation', $token);

                    break;
                case 'posted':
                    assert($scope !== null);
                    $clauses[] = $scope->getSql('date_available', $token);

                    break;
                case 'id':
                    assert($scope !== null);
                    $clauses[] = $scope->getSql($scopeId, $token);

                    break;
                default:
                    // $clauses is always [] here (this is the switch's own
                    // default arm; no other case falls through into it).
                    $hookClauses = $this->eventDispatcher
                        ->dispatch(new QsearchGetImagesSqlScopes($clauses, $token, $expr))
                        ->clauses;
                    foreach ($hookClauses as $hookClause) {
                        $clauses[] = $hookClause->sql;
                        $params = array_merge($params, $hookClause->params);
                    }

                    break;
            }

            if ($clauses !== []) {
                $qsr->images_iids[$i] = $this->repo->findImageIdsByRawWhere('(' . implode("\n OR ", $clauses) . ')', $params);
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

            [$clauses, $params] = $this->qsearchGetTextTokenSearchSql($token, ['name'], 'tags_fts');
            $rows = $this->repo->findTagRowsByRawWhere('(' . implode("\n OR ", $clauses) . ')', $params);
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
                $qsr->tag_iids[$i] = $this->tagService->getImageIdsForTags(array_map(TagId::from(...), $tagIds), 'OR', null, null, false);
                if ((bool) ($expr->stoken_modifiers[$i] & QSingleToken::QST_NOT)) {
                    $notIds = array_merge($notIds, $tagIds);
                } elseif (strlen($token->term) > 2 || count($expr->stokens) === 1 || isset($token->scope) || (bool) ($token->modifier & (QSingleToken::QST_WILDCARD | QSingleToken::QST_QUOTED))) {
                    $positiveIds = array_merge($positiveIds, $tagIds);
                }
            } elseif (isset($token->scope) && $token->scope->id === 'tag' && strlen($token->term) === 0) {
                if ((bool) ($token->modifier & QSingleToken::QST_WILDCARD)) {
                    $qsr->tag_iids[$i] = $this->tagService->getAllTaggedImageIds();
                } else {
                    $qsr->tag_iids[$i] = $this->imageService->getIdsWithNoTag();
                }
            }
        }

        $allTags = array_intersect_key($allTags, array_flip(array_diff($positiveIds, $notIds)));
        usort($allTags, $this->htmlRenderer->tagAlphaCompare(...));
        foreach ($allTags as &$tag) {
            $nameEvent = $this->eventDispatcher->dispatch(new RenderTagName(is_string($tag['name']) ? $tag['name'] : '', $tag));
            $tag['name'] = $nameEvent->tagName;
        }

        unset($tag);
        $qsr->all_tags = $allTags;
        $qsr->tag_ids = $tokenTagIds;
    }

    public function qsearchGetCategories(QExpression $expr, QResults $qsr): void
    {
        // CurrentUser::get()->forbiddenCategories (private/locked
        // categories via calculate_permissions(), extended with 0-image
        // categories for non-admins) already holds the set of categories
        // to exclude here. Reading it directly needs no query at all, on
        // either a cache-hit or cache-miss request.
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

            [$clauses, $params] = $this->qsearchGetTextTokenSearchSql($token, ['name', 'comment'], 'categories_fts');
            $rows = $this->repo->findCategoryRowsByRawWhere(
                '(' . implode("\n OR ", $clauses) . ') AND id NOT IN (' . $forbiddenPlaceholders . ')',
                [...$params, ...$forbiddenIds]
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
                if ($this->currentConfig->quickSearchIncludeSubAlbums) {
                    $subcatIds = $this->categoryService->getSubcatIds($catIds);
                    $catIds = $subcatIds !== []
                        ? $this->categoryService->getIdsAmongExcluding($subcatIds, $forbiddenIds)
                        : [];
                }

                $qsr->cat_iids[$i] = $catIds !== [] ? $this->imageService->getIdsInCategories($catIds) : [];
                if ((bool) ($expr->stoken_modifiers[$i] & QSingleToken::QST_NOT)) {
                    $notIds = array_merge($notIds, $catIds);
                } elseif (strlen($token->term) > 2 || count($expr->stokens) === 1 || isset($token->scope) || (bool) ($token->modifier & (QSingleToken::QST_WILDCARD | QSingleToken::QST_QUOTED))) {
                    $positiveIds = array_merge($positiveIds, $catIds);
                }
            } elseif (isset($token->scope) && $token->scope->id === 'category' && strlen($token->term) === 0) {
                if ((bool) ($token->modifier & QSingleToken::QST_WILDCARD)) {
                    $qsr->cat_iids[$i] = $this->imageService->getAllCategorizedIds();
                } else {
                    $qsr->cat_iids[$i] = $this->imageService->getIdsWithNoCategory();
                }
            }
        }

        $allCats = array_intersect_key($allCats, array_flip(array_diff($positiveIds, $notIds)));
        usort($allCats, $this->htmlRenderer->tagAlphaCompare(...));
        foreach ($allCats as &$cat) {
            $nameEvent = $this->eventDispatcher->dispatch(new RenderCategoryName(is_string($cat['name']) ? $cat['name'] : '', $cat));
            $cat['name'] = $nameEvent->categoryName;
        }

        unset($cat);
        $qsr->all_cats = $allCats;
        $qsr->cat_ids = $tokenCatIds;
    }

    /**
     * Pure computation over `QResults` -- no DB access of its own.
     * Recursive AND/OR/NOT boolean-expression evaluation over
     * already-fetched id sets.
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
                // A token matching a real tag/category name with zero
                // images still qualifies; only a token matching nothing
                // belongs in $ignoredTerms.
                $crtQualifies = count($crtIds) > 0 || count($qsr->tag_ids[$crt->idx]) > 0 || count($qsr->cat_ids[$crt->idx]) > 0;
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
     * $orderByOverride, when given, is used instead of
     * `$this->currentConfig->orderBy` for this call only -- callers that
     * need a request-scoped sort order (e.g. `Controller\Api\Images\
     * ImageSearchController`'s own `order` query param) pass it explicitly
     * instead of mutating the shared
     * CurrentConfig instance, which would otherwise leak into every other
     * consumer for the rest of the request (and across requests under
     * worker mode).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function getQuickSearchResults(string $q, array $options, ?PhotoSortOrder $orderByOverride = null): array
    {
        $user = $this->currentUser->get();

        $pool = $this->searchResultsCachePool;
        $cacheKey = md5(serialize([
            strtolower($q),
            $orderByOverride ?? $this->currentConfig->orderBy,
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

        $res = $this->getQuickSearchResultsNoCache($q, $options, $orderByOverride);
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
     * $orderByOverride: see getQuickSearchResults()'s own docblock.
     *
     * @param  array<string, mixed>  $options
     * @return array{items: array<int, mixed>, qs: array<string, mixed>, debug: list<string>, ...<string, mixed>}
     */
    public function getQuickSearchResultsNoCache(string $q, array $options, ?PhotoSortOrder $orderByOverride = null): array
    {
        $q = trim($q);
        $searchResults = [
            'items' => [],
            'qs' => [
                'q' => $q,
                'unmatched_terms' => [],
            ],
        ];

        /** @var list<string> $debug */
        $debug = [];

        $q = $this->eventDispatcher->dispatch(new QsearchPre($q))
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
        if ($this->currentConfig->calendarDatefield === 'date_creation') {
            $createdDateAliases[] = 'date';
        } else {
            $postedDateAliases[] = 'date';
        }

        $scopes[] = new QDateRangeScope('created', $createdDateAliases, true);
        $scopes[] = new QDateRangeScope('posted', $postedDateAliases);

        $scopesAfterHook = $this->eventDispatcher->dispatch(new QsearchGetScopes($scopes))
            ->scopes;
        $scopes = array_values(array_filter($scopesAfterHook, static fn (mixed $s): bool => $s instanceof QSearchScope));
        $expression = new QExpression($q, $scopes);

        $inflector = null;
        $langCode = substr($this->userService->getDefaultLanguage(), 0, 2);
        $className = '\\Piwigo\\Search\\Inflector\\Inflector' . ucfirst($langCode);
        if (class_exists($className)) {
            $inflector = new $className();
            if (! $inflector instanceof InflectorInterface) {
                throw new LogicException("qsearch: {$className} does not implement InflectorInterface");
            }

            foreach ($expression->stokens as $token) {
                if (isset($token->scope) && ! $token->scope->is_text) {
                    continue;
                }

                if (strlen($token->term) > 2
                    && ($token->modifier & (QSingleToken::QST_QUOTED | QSingleToken::QST_WILDCARD)) === 0
                    && strcspn($token->term, '\'0123456789') === strlen($token->term)) {
                    $token->variants = array_unique(array_diff($inflector->getVariants($token->term), [$token->term]));
                }
            }
        }

        $this->eventDispatcher->dispatch(new QsearchExpressionParsed($expression));

        if (count($expression->stokens) === 0) {
            $searchResults['debug'] = $debug;

            return $searchResults;
        }

        $qsr = new QResults();
        $this->qsearchGetTags($expression, $qsr);
        $this->qsearchGetCategories($expression, $qsr);
        $this->qsearchGetImages($expression, $qsr);

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
        $searchResultsAfterHook = $this->eventDispatcher->dispatch(new QsearchResults($searchResults, $expression, $qsr))
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
        // 'images_where' arrives as a real SqlCondition, so its values stay
        // bound: positionalCondition() rewrites the named placeholders into
        // the `?` form the rest of this query uses and hands back the
        // matching values, which have to be appended in clause order.
        $imagesWhere = $options['images_where'] ?? null;
        if ($imagesWhere instanceof SqlCondition && ! $imagesWhere->isEmpty()) {
            [$imagesWhereSql, $imagesWhereValues] = $this->positionalCondition($imagesWhere);
            $whereClauses[] = '(' . $imagesWhereSql . ')';
            $params = [...$params, ...$imagesWhereValues];
        }

        if ($permissions) {
            $criteria = $this->permissionService->getPermissionCriteria();
            [$permissionSql, $permissionValues] = $this->positionalCondition(SqlCondition::combine(
                'AND',
                $criteria->forbiddenCategoriesCondition('category_id'),
                $criteria->maxLevelCondition('i.level'),
            ));
            // The old $forceOneCondition: true guaranteed a non-empty
            // fragment here -- $whereClauses is later joined via Doctrine's
            // own ExpressionBuilder::and(), which doesn't skip empty parts
            // (a bare "()" is a real SQL syntax error), so an empty
            // fragment must be skipped here instead of pushed.
            if ($permissionSql !== '') {
                $whereClauses[] = $permissionSql;
                $params = [...$params, ...$permissionValues];
            }
        }

        // `SELECT DISTINCT(id) ... ORDER BY <col not in select>` is invalid
        // under ONLY_FULL_GROUP_BY -- Piwigo\Db\DbConnection deliberately
        // doesn't strip that sql_mode the way the legacy dblayer does (see
        // its own docblock), so `GROUP BY id` (functionally dependent via
        // the primary key) replaces DISTINCT here, same fix as
        // CalendarRepository::findImageIds().
        $orderBySqlBody = $this->sortRenderer->toSqlBody($orderByOverride ?? $this->currentConfig->orderBy);
        $whereSql = (string) $this->repo->expressionBuilder()
            ->and(...$whereClauses);
        $ids = $this->repo->findImageIdsForRegularSearch($whereSql, $params, $permissions, $orderBySqlBody);

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
                throw new Exception('splitAllwords(): preg_split() failed');
            }

            $words = array_unique($split);
        }

        return $words;
    }

    public function getAvailableSearchUuid(): string
    {
        $candidate = 'psk-' . Env::now()->format('Ymd') . '-' . $this->sessionService->generateKey(10);

        if ($this->repo->countSavedSearchByUuid($candidate) === 0) {
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
        $dbNow = Env::now()->format('Y-m-d H:i:s');
        $searchUuid = $this->getAvailableSearchUuid();

        $userId = $this->currentUser->get()
            ->id->value;

        $this->repo->insertSavedSearch($rules, $dbNow, $userId, $searchUuid, $forkedFrom);

        if (! $this->accessLevelChecker->isAGuest() && ! $this->accessLevelChecker->isGeneric()) {
            $rulesFields = $rules['fields'] ?? [];
            $this->preferencesService->updateParam('gallery_search_filters', array_keys(is_array($rulesFields) ? $rulesFields : []));
        }

        $url = $urlService->makeIndexUrl([
            'section' => 'search',
            'search' => $searchUuid,
        ]);

        return [$searchUuid, $url];
    }

    /**
     * $imagesWhere stays a raw string here -- it also feeds the
     * quicksearch dispatch branch below (`'images_where'`), a genuinely
     * raw-SQL-text option consumed by the qsearch token evaluator's own
     * permanent DBAL boundary (see SearchRepository's class docblock).
     * No real production caller ever passes a non-default value (traced
     * via SectionPopulator.php's own single real call site) -- the regular-
     * search dispatch branch below wraps it into a SqlCondition right at
     * the point of use, since getRegularSearchResults() itself now targets
     * DQL and needs a real bindable condition, not raw text.
     *
     * @return array<string, mixed>
     */
    public function getSearchResults(int|string $searchId, ?bool $superOrderBy, string $imagesWhere = ''): array
    {
        $search = $this->getSearchArray($searchId);
        if ($search === false) {
            $this->htmlRenderer->badRequest($this->redirectService, 'this search identifier does not exist');
        }

        if (! isset($search['q']) || ! is_string($search['q'])) {
            return $this->getRegularSearchResults($search, SqlCondition::fromRawSql($imagesWhere));
        }

        return $this->getQuickSearchResults($search['q'], [
            'super_order_by' => $superOrderBy,
            'images_where' => $imagesWhere,
        ]);
    }
}
