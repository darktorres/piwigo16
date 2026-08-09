<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Doctrine\DBAL\ArrayParameterType;
use Exception;
use LogicException;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Cache\CachePools;
use Piwigo\Category\CategoryService;
use Piwigo\Common\Enum\Section;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Env;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\PageFilterHelper;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\Event\Search\QsearchGetScopes;
use Piwigo\Event\Search\QsearchPre;
use Piwigo\Event\Tag\RenderTagName;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Group\GroupEntity;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\Event\QsearchBeforeEval;
use Piwigo\Search\Event\QsearchExpressionParsed;
use Piwigo\Search\Event\QsearchGetImagesSqlScopes;
use Piwigo\Search\Event\QsearchResults;
use Piwigo\Search\Inflector\InflectorInterface;
use Piwigo\Search\Projection\Search;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagEntity;
use Piwigo\Tag\TagService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
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
    /**
     * $tagService/$userService/$preferencesService are optional-with-
     * lazy-default (same reasoning as Mail\MailService's own
     * $webmasterMailProvider): several Controller/Ws/Admin call sites
     * still construct this class without them, and each is only reached
     * on the tags/default-language/saveSearch paths.
     */
    public function __construct(
        private AccessLevelChecker $accessLevelChecker,
        private SearchRepository $repo,
        private PermissionService $permissionService,
        private CategoryService $categoryService,
        private MailerInterface $mailer,
        private HtmlRenderingInterface $htmlRenderer,
        private RedirectServiceInterface $redirectService,
        private SessionService $sessionService,
        private EventDispatcher $eventDispatcher,
        private CurrentUser $currentUser,
        private Lang $lang,
        private readonly CurrentConfig $currentConfig,
        private CurrentLogger $currentLogger,
        private DeploymentPolicy $deploymentPolicy,
        private Paths $paths,
        private ?TagService $tagService = null,
        private ?UserService $userService = null,
        private ?PreferencesService $preferencesService = null,
    ) {}

    /**
     * DRY-extraction helper (Arch\StructuralTest's own "does not repeat
     * the same multi-dependency service construction chain" rule) --
     * $this->tagService is optional-with-lazy-default (see constructor's
     * own docblock); this class is `readonly`, so the lazily-built
     * instance can't be cached back onto the property (a second write
     * to an already-initialized readonly property is a hard error), it's
     * simply recomputed on demand by every caller.
     */
    private function tagService(): TagService
    {
        return $this->tagService ?? new TagService($this->lang, EntityManagerFactory::build(DbConnection::build())->getRepository(TagEntity::class), $this->permissionService, new ActivityService(EntityManagerFactory::build(DbConnection::build())->getRepository(ActivityEntity::class)), $this->eventDispatcher, $this->currentUser, $this->currentConfig, $this->currentLogger, $this->sessionService);
    }

    /**
     * Container resolve, not a constructor property -- used only inside
     * this class's own one `new UserService(...)` fallback below (matching
     * $this->tagService's own optional-with-lazy-default shape). Falls
     * back to a fresh, unmemoized instance when Kernel::boot() hasn't run.
     */
    private function processCache(): ProcessCache
    {
        if (Kernel::isBooted()) {
            $processCache = Kernel::container()->get(ProcessCache::class);
            if (! $processCache instanceof ProcessCache) {
                throw new LogicException('Container returned an unexpected type for ' . ProcessCache::class);
            }

            return $processCache;
        }

        return new ProcessCache();
    }

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
     * malformed candidate, refuses (outside the web-service API) to
     * resolve an old-style numeric-only id once the search row already
     * has a search_uuid (spies shouldn't be able to walk
     * index.php?/search/123, .../124, ...), and hands the resolved id
     * back through $resolvedSearchId for HistoryService::logVisit() to
     * read later, when rendering the "search" section.
     *
     * $section and $resolvedSearchId are explicit params. This method's
     * two real callers are SearchService::getValidatedSearchArray()
     * (reached from SearchFilterRenderer::render(), which passes
     * SectionContext::section, always available there, and returns the
     * resolved id up its own call chain to GalleryController) and
     * Ws\PwgImages::filteredSearchCreate() (a WS method that never runs
     * SectionPopulator, passes null section and no out-param --
     * `$page['section']` is never 'search' for a WS request either, so
     * nothing is written for that caller).
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

        if ($search !== null) {
            if (PageFilterHelper::scriptBasename($this->currentConfig) !== 'ws' and $clausePattern === 'id = ?' and $search->searchUuid !== null) {
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
     * own internal callers) and bad_request()s when nothing was found.
     *
     * @param int|null $resolvedSearchId in/out, see getValidatedSearchInfo()
     * @return array<string, mixed>|false
     */
    public function getValidatedSearchArray(int|string $searchId, ?Section $section, ?int &$resolvedSearchId = null): array|false
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
    public function getRegularSearchResults(array $search, SqlCondition $imagesWhere = new SqlCondition('')): array
    {
        $hasFiltersFilled = false;
        $matchingCatIds = null;
        $matchingTagIds = null;

        $forbidden = $this->forbiddenCondition();

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
                    || ($filtConf['access'] === 'admins-only' && $this->accessLevelChecker->isAdmin())
                    || ($filtConf['access'] === 'registered-users' && $this->accessLevelChecker->isClassicUser());
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
            $imageIdsForFilter['author'] = $this->queryImageIdsFor(
                new SqlCondition('i.author IN (:authorWords)', [
                    'authorWords' => $authorWords,
                ], [
                    'authorWords' => ArrayParameterType::STRING,
                ]),
                $forbidden
            );
        }

        // filetypes
        $filetypesField = $searchFields['filetypes'] ?? null;
        $filetypes = is_array($filetypesField) ? array_values(array_filter($filetypesField, is_string(...))) : [];
        if ($filetypes !== [] && (bool) ($displayFilters['file_type']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $clauses = [];
            $params = [];
            foreach ($filetypes as $i => $ext) {
                $clauses[] = "i.path LIKE :filetype{$i}";
                $params["filetype{$i}"] = '%.' . $ext;
            }

            $imageIdsForFilter['filetypes'] = $this->queryImageIdsFor(new SqlCondition('(' . implode(' OR ', $clauses) . ')', $params), $forbidden);
        }

        // added_by
        $addedByField = $searchFields['added_by'] ?? null;
        $addedByIds = is_array($addedByField) ? array_values(array_map(intval(...), array_filter($addedByField, is_numeric(...)))) : [];
        if ($addedByIds !== [] && (bool) ($displayFilters['added_by']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $imageIdsForFilter['added_by'] = $this->queryImageIdsFor(
                new SqlCondition('i.addedBy IN (:addedByIds)', [
                    'addedByIds' => $addedByIds,
                ], [
                    'addedByIds' => ArrayParameterType::INTEGER,
                ]),
                $forbidden
            );
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
                $imageIdsForFilter['cat'] = $this->queryImageIdsFor(
                    new SqlCondition('ic.categoryId IN (:catIds)', [
                        'catIds' => $catIds,
                    ], [
                        'catIds' => ArrayParameterType::INTEGER,
                    ]),
                    $forbidden
                );
            }
        }

        // date_posted
        $datePostedField = $searchFields['date_posted'] ?? null;
        $datePostedPreset = is_array($datePostedField) && is_string($datePostedField['preset'] ?? null) ? $datePostedField['preset'] : null;
        if ($datePostedPreset !== null && $datePostedPreset !== '' && (bool) ($displayFilters['post_date']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $condition = $this->dateFilterClause('i.dateAvailable', $datePostedPreset, $datePostedField, [
                '24h' => [24, 'HOUR'],
                '7d' => [7, 'DAY'],
                '30d' => [30, 'DAY'],
                '3m' => [3, 'MONTH'],
                '6m' => [6, 'MONTH'],
            ]);
            $imageIdsForFilter['date_posted'] = $this->queryImageIdsFor($condition, $forbidden);
        }

        // date_created
        $dateCreatedField = $searchFields['date_created'] ?? null;
        $dateCreatedPreset = is_array($dateCreatedField) && is_string($dateCreatedField['preset'] ?? null) ? $dateCreatedField['preset'] : null;
        if ($dateCreatedPreset !== null && $dateCreatedPreset !== '' && (bool) ($displayFilters['creation_date']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $condition = $this->dateFilterClause('i.dateCreation', $dateCreatedPreset, $dateCreatedField, [
                '7d' => [7, 'DAY'],
                '30d' => [30, 'DAY'],
                '3m' => [3, 'MONTH'],
                '6m' => [6, 'MONTH'],
                '12m' => [12, 'MONTH'],
            ]);
            $imageIdsForFilter['date_created'] = $this->queryImageIdsFor($condition, $forbidden);
        }

        // ratios
        $ratiosField = $searchFields['ratios'] ?? null;
        $ratios = is_array($ratiosField) ? array_values(array_filter($ratiosField, is_string(...))) : [];
        if ($ratios !== [] && (bool) ($displayFilters['ratio']['access'] ?? false)) {
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
                $imageIdsForFilter['ratios'] = $this->queryImageIdsFor(new SqlCondition('(' . implode(' OR ', $clauses) . ')'), $forbidden);
            }
        }

        // ratings
        $ratingsField = $searchFields['ratings'] ?? null;
        $ratings = is_array($ratingsField) ? array_values(array_filter($ratingsField, is_string(...))) : [];
        if ($this->currentConfig->rateEnabled() && $ratings !== [] && (bool) ($displayFilters['rating']['access'] ?? false)) {
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

            $imageIdsForFilter['ratings'] = $this->queryImageIdsFor(new SqlCondition('(' . implode(' OR ', $clauses) . ')', $ratingParams), $forbidden);
        }

        // filesize
        $filesizeMinRaw = $searchFields['filesize_min'] ?? null;
        $filesizeMaxRaw = $searchFields['filesize_max'] ?? null;
        if ($filesizeMinRaw !== null && $filesizeMinRaw !== 0 && $filesizeMaxRaw !== null && $filesizeMaxRaw !== 0 && is_numeric($filesizeMinRaw) && is_numeric($filesizeMaxRaw) && (bool) ($displayFilters['file_size']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $imageIdsForFilter['filesize'] = $this->queryImageIdsFor(
                new SqlCondition('i.filesize BETWEEN :filesizeMin AND :filesizeMax', [
                    'filesizeMin' => (float) $filesizeMinRaw - 100.0,
                    'filesizeMax' => (float) $filesizeMaxRaw + 100.0,
                ]),
                $forbidden
            );
        }

        // height
        $heightMinRaw = $searchFields['height_min'] ?? null;
        $heightMaxRaw = $searchFields['height_max'] ?? null;
        if ($heightMinRaw !== null && $heightMinRaw !== 0 && $heightMaxRaw !== null && $heightMaxRaw !== 0 && is_scalar($heightMinRaw) && is_scalar($heightMaxRaw) && (bool) ($displayFilters['height']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $imageIdsForFilter['height'] = $this->queryImageIdsFor(
                new SqlCondition('i.height BETWEEN :heightMin AND :heightMax', [
                    'heightMin' => $heightMinRaw,
                    'heightMax' => $heightMaxRaw,
                ]),
                $forbidden
            );
        }

        // width
        $widthMinRaw = $searchFields['width_min'] ?? null;
        $widthMaxRaw = $searchFields['width_max'] ?? null;
        if ($widthMinRaw !== null && $widthMinRaw !== 0 && $widthMaxRaw !== null && $widthMaxRaw !== 0 && is_scalar($widthMinRaw) && is_scalar($widthMaxRaw) && (bool) ($displayFilters['width']['access'] ?? false)) {
            $hasFiltersFilled = true;
            $imageIdsForFilter['width'] = $this->queryImageIdsFor(
                new SqlCondition('i.width BETWEEN :widthMin AND :widthMax', [
                    'widthMin' => $widthMinRaw,
                    'widthMax' => $widthMaxRaw,
                ]),
                $forbidden
            );
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
            $tagService = $this->tagService();
            $imageIdsForFilter['tags'] = array_values(array_map(intval(...), array_filter($tagService->getImageIdsForTags(array_map(TagId::from(...), $tagsWords), $tagsMode), is_numeric(...))));
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
        }, $condition->sql);
        if ($sql === null) {
            throw new RuntimeException('positionalCondition(): preg_replace_callback() failed');
        }

        return [$sql, array_values($values)];
    }

    /**
     * `PermissionCriteria`'s own 5 `*Condition(string): SqlCondition`
     * methods work unchanged against a DQL `QueryBuilder` -- a caller
     * just passes a DQL property path (`ic.categoryId`) instead of a raw
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
            $criteria->forbiddenCategoriesCondition('ic.categoryId'),
            $criteria->visibleCategoriesCondition('ic.categoryId'),
            $criteria->visibleImagesCondition('i.id'),
            $criteria->maxLevelCondition('i.level'),
        );
    }

    /**
     * Shared "images matching this WHERE fragment, filtered by the current
     * user's permissions" executor for every advanced-search criterion --
     * all 12 share the exact same `FROM ImageEntity i INNER JOIN
     * ImageCategoryEntity ic WITH ic.imageId = i.id WHERE <criterion>
     * <forbidden>` shape. $criterion is AND-combined with $forbidden
     * unparenthesized -- every real criterion built above already wraps
     * its own internal OR-joined clauses in parens itself when it has any,
     * same convention {@see \Piwigo\Ws\PwgCategories} already established.
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
    private function dateFilterClause(string $dqlColumn, string $preset, mixed $field, array $presetOptions): SqlCondition
    {
        if (isset($presetOptions[$preset])) {
            [$amount, $unit] = $presetOptions[$preset];

            return new SqlCondition(
                $dqlColumn . " > DATE_SUB(CURRENT_TIMESTAMP(), :dateAmount, '{$unit}')",
                [
                    'dateAmount' => $amount,
                ]
            );
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
                    $subconditions[] = new SqlCondition(
                        "({$dqlColumn} BETWEEN :dateBegin{$i} AND :dateEnd{$i})",
                        [
                            "dateBegin{$i}" => $begin,
                            "dateEnd{$i}" => $end,
                        ]
                    );
                }
            }

            $combined = SqlCondition::combine('OR', ...$subconditions);

            return $combined->isEmpty() ? $combined : new SqlCondition('(' . $combined->sql . ')', $combined->parameters, $combined->types);
        }

        return new SqlCondition('1=1');
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
     * @param  array<array-key, mixed>  $allwordsField
     * @param  list<string>  $words
     * @param  list<string>  $searchFields
     * @return array{0: list<int>, 1: ?list<int>, 2: ?list<int>}
     */
    private function searchAllwords(array $allwordsField, array $words, array $searchFields, SqlCondition $forbidden): array
    {
        $fields = array_intersect(['file', 'name', 'comment', 'author'], $searchFields);
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
        $catFields = array_intersect(array_keys($catFieldsDictionary), $searchFields);

        $wordConditions = [];
        $catIdsByWord = [];
        $tagIdsByWord = [];

        // The category-name/comment and tag-name lookups below, plus the
        // image lookups by matching category/tag ids, go through
        // CategoryService/TagService (which own those tables) instead of
        // SearchRepository's own generic findIdsByClause() -- cross-domain
        // sub-lookups, not a genuine search-domain query shape.
        $searchesTags = in_array('tags', $searchFields, true);
        $tagService = $searchesTags ? $this->tagService() : null;

        foreach ($words as $wordIndex => $word) {
            $fieldClauses = [];
            $params = [];
            $types = [];
            foreach ($fields as $field) {
                $paramName = "word{$wordIndex}_{$field}";
                $fieldClauses[] = $dqlFieldsByColumn[$field] . ' LIKE :' . $paramName;
                $params[$paramName] = '%' . $word . '%';
            }

            if ($catFields !== []) {
                $catIds = $this->categoryService->getIdsByNameOrCommentLike(
                    '%' . $word . '%',
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
                $wordConditions[] = new SqlCondition('(' . implode(' OR ', $fieldClauses) . ')', $params, $types);
            }
        }

        $allwordsMode = (is_string($allwordsField['mode'] ?? null) && in_array($allwordsField['mode'], ['OR', 'AND'], true))
            ? $allwordsField['mode']
            : 'AND';

        $combined = SqlCondition::combine($allwordsMode, ...$wordConditions);
        $filterCondition = $combined->isEmpty() ? $combined : new SqlCondition('(' . $combined->sql . ')', $combined->parameters, $combined->types);

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
     * @param  string[]  $fields
     * @return array{0: list<non-falsy-string>, 1: list<string>}
     */
    public function qsearchGetTextTokenSearchSql(QSingleToken $token, array $fields): array
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
        $isPostgres = DbCredentials::fromEnv()->driver === 'pgsql';

        $clauses = [];
        $values = [];
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
            } else {
                $clauses[] = 'MATCH(' . implode(', ', $fields) . ') AGAINST(? IN BOOLEAN MODE)';
                $values[] = implode(' ', $fts);
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

        $isPostgres = DbCredentials::fromEnv()->driver === 'pgsql';

        for ($i = 0; $i < count($expr->stokens); $i++) {
            $token = $expr->stokens[$i];
            $scope = $token->scope;
            $scopeId = $scope !== null ? $scope->id : 'photo';
            $clauses = [];
            $params = [];

            $like = str_replace(['%', '_'], ['\\%', '\\_'], $token->term);
            $fileLike = $isPostgres ? 'file LIKE ?' : 'CONVERT(file, CHAR) LIKE ?';
            $fileLikeValue = '%' . $like . '%';

            switch ($scopeId) {
                case 'photo':
                    $clauses[] = $fileLike;
                    $params[] = $fileLikeValue;
                    [$textClauses, $textValues] = $this->qsearchGetTextTokenSearchSql($token, ['name', 'comment']);
                    $clauses = array_merge($clauses, $textClauses);
                    $params = array_merge($params, $textValues);

                    break;

                case 'file':
                    $clauses[] = $fileLike;
                    $params[] = $fileLikeValue;

                    break;
                case 'author':
                    if ((bool) strlen($token->term)) {
                        [$textClauses, $textValues] = $this->qsearchGetTextTokenSearchSql($token, ['author']);
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
                    $clauses[] = $scope->get_sql($scopeId, $token);

                    break;
                case 'ratio':
                    assert($scope !== null);
                    // Same integer-division-truncation and divide-by-zero
                    // risk as getRegularSearchResults()'s own ratio
                    // buckets -- see that call site's docblock.
                    // `width*1.0` forces decimal-context arithmetic on
                    // both platforms; NULLIF(height, 0) guards a
                    // genuinely zero height the same way.
                    $clauses[] = $scope->get_sql('width*1.0/NULLIF(height, 0)', $token);

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
                    // $clauses is always [] here (this is the switch's own
                    // default arm; no other case falls through into it).
                    $hookClauses = $this->eventDispatcher
                        ->dispatchChange(new QsearchGetImagesSqlScopes($clauses, $token, $expr))
                        ->clauses;
                    foreach ($hookClauses as $hookClause) {
                        $clauses[] = $hookClause->sql;
                        $params = array_merge($params, $hookClause->params);
                    }

                    break;
            }

            if ($clauses !== []) {
                $qsr->images_iids[$i] = $this->repo->findIdsByClause('id', Tables::images() . ' i', '(' . implode("\n OR ", $clauses) . ')', $params);
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

            [$clauses, $params] = $this->qsearchGetTextTokenSearchSql($token, ['name']);
            $rows = $this->repo->findRowsByClause(Tables::tags(), '(' . implode("\n OR ", $clauses) . ')', $params);
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

            [$clauses, $params] = $this->qsearchGetTextTokenSearchSql($token, ['name', 'comment']);
            $rows = $this->repo->findRowsByClause(
                Tables::categories(),
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
                // A token that matched a real tag/category name -- even
                // one with zero images attached right now -- still
                // qualifies as a recognized search term; only a token
                // that matched nothing at all belongs in $ignoredTerms.
                // cat_ids/tag_ids are qsearchGetCategories()/
                // qsearchGetTags()'s own parallel "matched name" lists,
                // structurally identical to each other (found missing via
                // shipmonk/dead-code-detector flagging $qsr->cat_ids as
                // never read -- this was the real, single read site it
                // was missing).
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
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function getQuickSearchResults(string $q, array $options): array
    {
        $user = $this->currentUser->get();

        $pool = CachePools::searchResults();
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
        $userService = $this->userService ?? new UserService($this->lang, new UserRepository(EntityManagerFactory::build(DbConnection::build()), $this->eventDispatcher, $this->currentConfig), EntityManagerFactory::build(DbConnection::build())->getRepository(GroupEntity::class), $this->mailer, new ActivityService(EntityManagerFactory::build(DbConnection::build())->getRepository(ActivityEntity::class)), $this->htmlRenderer, DbConnection::build(), $this->sessionService, $this->eventDispatcher, $this->deploymentPolicy, $this->currentUser, $this->currentConfig, new InstallationFlag(), $this->processCache(), $this->paths);
        $langCode = substr($userService->getDefaultLanguage(), 0, 2);
        $className = '\\Piwigo\\Search\\Inflector\\Inflector_' . $langCode;
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
        $whereSql = (string) $this->repo->expressionBuilder()
            ->and(...$whereClauses);
        $ids = $this->repo->findIdsByClause('id', $from, $whereSql . "\nGROUP BY id\n" . $orderBy, $params);

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
        $candidate = 'psk-' . date('Ymd') . '-' . $this->sessionService->generateKey(10);

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
            $preferencesService = $this->preferencesService ?? new PreferencesService(new UserRepository(EntityManagerFactory::build(DbConnection::build()), $this->eventDispatcher, $this->currentConfig), $this->currentUser);
            $preferencesService->updateParam('gallery_search_filters', array_keys(is_array($rulesFields) ? $rulesFields : []));
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
            return $this->getRegularSearchResults($search, new SqlCondition($imagesWhere));
        }

        return $this->getQuickSearchResults($search['q'], [
            'super_order_by' => $superOrderBy,
            'images_where' => $imagesWhere,
        ]);
    }
}
