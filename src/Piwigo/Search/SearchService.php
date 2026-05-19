<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Projection\CategoryNamePermalink;
use Piwigo\Config\Config;
use Piwigo\Core\AppInfo;
use Piwigo\Core\StringUtil;
use Piwigo\Db\DbInfo;
use Piwigo\Event\Search\QsearchBeforeEval;
use Piwigo\Event\Search\QsearchExpressionParsed;
use Piwigo\Event\Search\QsearchGetImagesSqlScopes;
use Piwigo\Event\Search\QsearchGetScopes;
use Piwigo\Event\Search\QsearchPre;
use Piwigo\Event\Search\QsearchResults;
use Piwigo\Event\Tag\RenderTagName;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Exception\ValidationException;
use Piwigo\Html\HtmlService;
use Piwigo\Image\OrderByService;
use Piwigo\Search\Inflector\InflectorEn;
use Piwigo\Search\Inflector\InflectorFr;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

final class SearchService
{
    /** @var array<string,mixed> */
    private array $searchDetails = [];
    private ?string $searchId    = null;
    private ?bool $useRegexpICU  = null;

    public function __construct(
        private readonly SearchRepository $searchRepo,
        private readonly SearchFilterViewRepository $filterViewRepo,
        private readonly CategoryRepository $categoryRepository,
        private readonly LoggerInterface $logger,
        private readonly CategoryService $categoryService,
        private readonly HtmlService $htmlService,
        private readonly PermissionService $permissionService,
        private readonly PreferencesService $preferencesService,
        private readonly TagRepository $tagRepository,
        private readonly TagService $tagService,
        private readonly UrlService $urlService,
        private readonly UserService $userService,
        private readonly CacheItemPoolInterface $pool,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly OrderByService $orderByService,
    ) {
    }

    /** @return array<string,mixed> */
    public function getSearchDetails(): array
    {
        return $this->searchDetails;
    }

    /** @param array<mixed> $details */
    public function setSearchDetails(array $details): void
    {
        /** @var array<string,mixed> $normalized */
        $normalized = [];
        foreach ($details as $key => $value) {
            $normalized[(string) $key] = $value;
        }
        $this->searchDetails = $normalized;
    }

    /**
     * @param array{0: string, 1: list<mixed>, 2: list<\Doctrine\DBAL\ArrayParameterType|\Doctrine\DBAL\ParameterType>} $forbidden
     */
    public function setForbidden(array $forbidden): void
    {
        $this->searchDetails['forbidden'] = $forbidden;
    }

    public function getSearchId(): ?string
    {
        return $this->searchId;
    }

    public function getSearchIdPattern(string $candidate): ?string
    {
        $clause_pattern = null;
        if (preg_match('/^psk-\d{8}-[a-z0-9]{10}$/i', $candidate)) {
            $clause_pattern = 'search_uuid = \'%s\'';
        } elseif (preg_match('/^\d+$/', $candidate)) {
            $clause_pattern = 'id = %u';
        }
        return $clause_pattern;
    }

    /** @return array<string,mixed>|null */
    public function getSearchInfo(string $candidate): ?array
    {
        $clausePattern = $this->getSearchIdPattern($candidate);
        if ($clausePattern === null || $clausePattern === '') {
            throw new ValidationException('Invalid search identifier');
        }
        $idColumn = $clausePattern === 'id = %u' ? 'id' : 'search_uuid';

        $row = $this->searchRepo->findSearchRow($idColumn, $candidate);
        if ($row !== null) {
            if (StringUtil::scriptBasename() != 'ws' and 'id = %u' == $clausePattern and isset($row['search_uuid'])) {
                HtmlService::fatalError('this search is not reachable with its id, need the search_uuid instead');
            }
            if ('search' == SectionContextRegistry::current()->section) {
                $rawId = $row['id'] ?? null;
                $this->searchId = is_scalar($rawId) ? (string) $rawId : null;
            }
            return $row;
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function getSearchArray(string $searchId): array
    {
        $search = $this->getSearchInfo($searchId);
        if ($search === null || count($search) === 0) {
            $this->htmlService->badRequest('this search identifier does not exist');
        }
        $rules  = $search['rules'] ?? '';
        if (!is_string($rules) || $rules === '') {
            return [];
        }
        $decoded = json_decode($rules, associative: true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $key => $val) {
            if (is_string($key)) {
                $out[$key] = $val;
            }
        }
        return $out;
    }

    /**
     * Run the persisted "regular" search (i.e. not quick-search) against
     * each enabled filter and intersect the matching image-id sets. The
     * `$search` shape mirrors the JSON stored in `search.rules` and is
     * loose by necessity: every key is optional, the user may submit
     * scalar values where the filter wants a list, etc. Each block below
     * narrows just-in-time at the field it consumes; mixed reads here are
     * boundary-edge, not domain code.
     *
     * @param array{
     *     fields?: array<string, mixed>,
     *     mode?: string,
     * }|array<string, mixed> $search
     * @return array<string, mixed>
     */
    public function getRegularSearchResults(array $search, string $imagesWhere = ''): array
    {
        $this->logger->debug('getRegularSearchResults', $search);

        $hasFilersFilled = false;

        [$forbiddenSql, $forbiddenParams, $forbiddenTypes] = $this->permissionService->getSqlConditionFandF(
            ['forbidden_categories' => 'category_id', 'visible_categories' => 'category_id', 'visible_images' => 'id'],
            "\n  AND"
        );

        // Closure binds the parameterized permission filter once and runs the
        // per-filter "SELECT DISTINCT(id) FROM images JOIN image_category
        // WHERE <filter> $forbiddenSql" shape used by every filter below.
        $filterImageIds =
            /** @return list<int> */
            fn (string $whereClause): array => $this->searchRepo->findDistinctImageIdsByWhereWithPermissions(
                $whereClause,
                $forbiddenSql,
                $forbiddenParams,
                $forbiddenTypes,
            );

        $imageIdsForFilter = [];

        $rawFilters = $this->filterViewRepo->listAll();

        // Resolve each filter's `access` from the persisted role string
        // ('everybody' / 'admins-only' / 'registered-users') to a per-user
        // bool. The `last_filters_conf` entry (bool, not array) is left
        // alone since it doesn't drive a per-filter access gate.
        /** @var array<string, array{access: bool, default: bool}> $displayFilters */
        $displayFilters = [];
        foreach ($rawFilters as $filtName => $filtConf) {
            if (!is_array($filtConf)) {
                continue;
            }
            $access = $filtConf['access'];
            $resolvedAccess = $access === 'everybody'
                || ($access === 'admins-only' && $this->permissionService->isAdmin())
                || ($access === 'registered-users' && $this->permissionService->isClassicUser());
            $displayFilters[$filtName] = ['access' => $resolvedAccess, 'default' => $filtConf['default']];
        }

        $defaultFilt        = ['access' => false, 'default' => false];
        $expertFilter       = $displayFilters['expert']        ?? $defaultFilt;
        $allwordsFilter     = $displayFilters['words']         ?? $defaultFilt;
        $authorFilter       = $displayFilters['author']        ?? $defaultFilt;
        $filetypeFilter     = $displayFilters['file_type']     ?? $defaultFilt;
        $addedByFilter      = $displayFilters['added_by']      ?? $defaultFilt;
        $albumFilter        = $displayFilters['album']         ?? $defaultFilt;
        $postDateFilter     = $displayFilters['post_date']     ?? $defaultFilt;
        $creationDateFilter = $displayFilters['creation_date'] ?? $defaultFilt;
        $ratioFilter        = $displayFilters['ratio']         ?? $defaultFilt;
        $ratingFilter       = $displayFilters['rating']        ?? $defaultFilt;
        $fileSizeFilter     = $displayFilters['file_size']     ?? $defaultFilt;
        $heightFilter       = $displayFilters['height']        ?? $defaultFilt;
        $widthFilter        = $displayFilters['width']         ?? $defaultFilt;
        $tagsFilter         = $displayFilters['tags']          ?? $defaultFilt;

        /** @var array<string, mixed> $searchFields */
        $searchFields = is_array($search['fields'] ?? null) ? $search['fields'] : [];

        /** @var array{string?: string} $expertFields */
        $expertFields = is_array($searchFields['expert'] ?? null) ? $searchFields['expert'] : [];
        if (isset($searchFields['expert']) and !empty($expertFields['string']) and $expertFilter['access']) {
            $hasFilersFilled = true;
            $expertString = $expertFields['string'];
            $expertQsr    = $this->getQuickSearchResults($expertString, []) ?? [];
            $imageIdsForFilter['expert'] = is_array($expertQsr['items'] ?? null) ? $expertQsr['items'] : [];
        }

        /** @var array{words?: list<string>|string, fields?: list<string>, mode?: string} $allwordsFields */
        $allwordsFields = is_array($searchFields['allwords'] ?? null) ? $searchFields['allwords'] : [];
        $allwordsFieldsFields = $allwordsFields['fields'] ?? null;
        $allwordsWordsRaw = $allwordsFields['words'] ?? null;
        $allwordsHasWords = (is_array($allwordsWordsRaw) && count($allwordsWordsRaw) > 0)
            || (is_string($allwordsWordsRaw) && $allwordsWordsRaw !== '');
        if (isset($searchFields['allwords']) and $allwordsHasWords and count(is_array($allwordsFieldsFields) ? $allwordsFieldsFields : []) > 0 and $allwordsFilter['access']) {
            $hasFilersFilled = true;
            $fields = ['file', 'name', 'comment', 'author'];
            $allwordsFieldList = is_array($allwordsFieldsFields) ? $allwordsFieldsFields : [];
            if (isset($allwordsFields['fields'])) {
                $fields = array_intersect($fields, $allwordsFieldList);
            }
            $catFieldsDictionary = ['cat-title' => 'name', 'cat-desc' => 'comment'];
            $catFields           = array_intersect(array_keys($catFieldsDictionary), $allwordsFieldList);
            $wordClauses         = [];
            $catIdsByWord = $tagIdsByWord = [];
            $allwordsWords = is_array($allwordsFields['words']) ? $allwordsFields['words'] : [];
            foreach ($allwordsWords as $word) {
                $fieldClauses = [];
                foreach ($fields as $field) {
                    $fieldClauses[] = $field . " LIKE '%" . $word . "%'";
                }
                if (count($catFields) > 0) {
                    $catFieldClauses = [];
                    foreach ($catFields as $catField) {
                        $catFieldClauses[] = $catFieldsDictionary[$catField] . " LIKE '%" . $word . "%'";
                    }
                    $catIds = $this->categoryRepository->findIdsByOrClauses($catFieldClauses);
                    $catIdsByWord[$word] = $catIds;
                    if ($catIds !== []) {
                        $catImageIds = $this->categoryRepository->findDistinctImageIdsGroupedByCategoryIds($catIds);
                        if ($catImageIds !== []) {
                            $fieldClauses[] = 'id IN (' . implode(',', $catImageIds) . ')';
                        }
                    }
                }
                if (in_array('tags', $allwordsFieldList)) {
                    $tagIds = $this->tagRepository->findIdsByNameLike($word);
                    $tagIdsByWord[$word] = $tagIds;
                    if ($tagIds !== []) {
                        $tagImageIds = $this->tagRepository->findDistinctImageIdsGroupedByTagIds($tagIds);
                        if ($tagImageIds !== []) {
                            $fieldClauses[] = 'id IN (' . implode(',', $tagImageIds) . ')';
                        }
                    }
                }
                if (count($fieldClauses) > 0) {
                    $wordClauses[] = implode("\n          OR ", $fieldClauses);
                }
            }
            if (count($wordClauses) > 0) {
                array_walk($wordClauses, function (string &$s): void {
                    $s = '(' . $s . ')';
                });
            }
            $allwordsModeRaw = $allwordsFields['mode'] ?? null;
            $allwordsMode = is_scalar($allwordsModeRaw) ? (string) $allwordsModeRaw : 'AND';
            if (!in_array($allwordsMode, ['OR', 'AND'])) {
                $allwordsMode = 'AND';
            }
            $filterClause = "\n         " . implode("\n         " . $allwordsMode . "\n         ", $wordClauses);
            $imageIdsForFilter['allwords'] = $filterImageIds($filterClause);

            if (count($catIdsByWord) > 0) {
                $matchingCatIds = null;
                foreach ($catIdsByWord as $catIds) {
                    $matchingCatIds = is_null($matchingCatIds) ? $catIds : array_merge($matchingCatIds, $catIds);
                }
                $matchingCatIds = array_unique($matchingCatIds);
            }
            if (count($tagIdsByWord) > 0) {
                $matchingTagIds = null;
                foreach ($tagIdsByWord as $tagIds) {
                    $matchingTagIds = is_null($matchingTagIds) ? $tagIds : array_merge($matchingTagIds, $tagIds);
                }
                $matchingTagIds = array_unique($matchingTagIds);
            }
        }

        /** @var array{words?: list<string>} $authorFields */
        $authorFields = is_array($searchFields['author'] ?? null) ? $searchFields['author'] : [];
        $authorWords  = is_array($authorFields['words'] ?? null) ? $authorFields['words'] : [];
        if (isset($searchFields['author']) and count($authorWords) > 0 and $authorFilter['access']) {
            $hasFilersFilled  = true;
            $authorClauses = [];
            foreach ($authorWords as $word) {
                $authorClauses[] = "author = '" . $word . "'";
            }
            $imageIdsForFilter['author'] = $filterImageIds('(' . implode(' OR ', $authorClauses) . ')');
        }

        /** @var list<string> $filetypesList */
        $filetypesList = is_array($searchFields['filetypes'] ?? null) ? $searchFields['filetypes'] : [];
        if (!empty($searchFields['filetypes']) and $filetypeFilter['access']) {
            $hasFilersFilled = true;
            $filetypesClauses = [];
            foreach ($filetypesList as $ext) {
                $filetypesClauses[] = 'path LIKE \'%.' . $ext . '\'';
            }
            $imageIdsForFilter['filetypes'] = $filterImageIds('(' . implode(' OR ', $filetypesClauses) . ')');
        }

        /** @var list<int|string> $addedByRaw */
        $addedByRaw = is_array($searchFields['added_by'] ?? null) ? $searchFields['added_by'] : [];
        $addedByList = array_map(static fn (int|string $v): string => (string) $v, $addedByRaw);
        if (!empty($searchFields['added_by']) and $addedByFilter['access']) {
            $hasFilersFilled = true;
            $imageIdsForFilter['added_by'] = $filterImageIds('added_by IN (' . implode(',', $addedByList) . ')');
        }

        /** @var array{words?: list<int|string>, sub_inc?: bool|int|string} $catFieldsData */
        $catFieldsData = is_array($searchFields['cat'] ?? null) ? $searchFields['cat'] : [];
        /** @var list<int|string> $catWordsRaw */
        $catWordsRaw = is_array($catFieldsData['words'] ?? null) ? $catFieldsData['words'] : [];
        $catWords    = array_map(static fn (int|string $v): string => (string) $v, $catWordsRaw);
        if (isset($searchFields['cat']) and !empty($catWords) and $albumFilter['access']) {
            $hasFilersFilled = true;
            $catSubInc = !empty($catFieldsData['sub_inc']);
            $catIds    = $catSubInc ? $this->categoryService->getSubcatIds($catWords) : $catWords;
            if (!empty($catIds)) {
                $imageIdsForFilter['cat'] = $filterImageIds('category_id IN (' . implode(',', $catIds) . ')');
            }
        }

        // date_posted
        /** @var array{preset?: string, custom?: list<string>} $datePostedFields */
        $datePostedFields = is_array($searchFields['date_posted'] ?? null) ? $searchFields['date_posted'] : [];
        $datePostedPreset = is_string($datePostedFields['preset'] ?? null) ? $datePostedFields['preset'] : '';
        if (!empty($datePostedPreset) and $postDateFilter['access']) {
            $hasFilersFilled = true;
            $options = ['24h' => '24 HOUR', '7d' => '7 DAY', '30d' => '30 DAY', '3m' => '3 MONTH', '6m' => '6 MONTH'];
            $datePostedClause = '';
            if (isset($options[$datePostedPreset])) {
                $datePostedClause = 'date_available > SUBDATE(NOW(), INTERVAL ' . $options[$datePostedPreset] . ')';
            } elseif ('custom' == $datePostedPreset and isset($datePostedFields['custom'])) {
                $datePostedSubclauses = [];
                $datePostedCustom = $datePostedFields['custom'];
                $customDates = array_flip($datePostedCustom);
                foreach (array_keys($customDates) as $customDate) {
                    $begin = $end = null;
                    $ymd   = substr($customDate, 0, 1);
                    if ('y' == $ymd) {
                        $year = substr($customDate, 1);
                        $begin = $year . '-01-01 00:00:00';
                        $end   = $year . '-12-31 23:59:59';
                    } elseif ('m' == $ymd) {
                        [$year, $month] = explode('-', substr($customDate, 1));
                        if (!isset($customDates['y' . $year])) {
                            $begin = $year . '-' . $month . '-01 00:00:00';
                            $end   = $year . '-' . $month . '-' . cal_days_in_month(CAL_GREGORIAN, (int) $month, (int) $year) . ' 23:59:59';
                        }
                    } elseif ('d' == $ymd) {
                        [$year, $month, $day] = explode('-', substr($customDate, 1));
                        if (!isset($customDates['y' . $year]) and !isset($customDates['m' . $year . '-' . $month])) {
                            $begin = $year . '-' . $month . '-' . $day . ' 00:00:00';
                            $end   = $year . '-' . $month . '-' . $day . ' 23:59:59';
                        }
                    }
                    if (!empty($begin) && $end !== null) {
                        $datePostedSubclauses[] = 'date_available BETWEEN "' . $begin . '" AND "' . $end . '"';
                    }
                }
                $datePostedClause = '(' . implode(' OR ', StringUtil::prependAppendArrayItems($datePostedSubclauses, '(', ')')) . ')';
            }
            $imageIdsForFilter['date_posted'] = $filterImageIds($datePostedClause);
        }

        // date_created
        /** @var array{preset?: string, custom?: list<string>} $dateCreatedFields */
        $dateCreatedFields = is_array($searchFields['date_created'] ?? null) ? $searchFields['date_created'] : [];
        $dateCreatedPreset = is_string($dateCreatedFields['preset'] ?? null) ? $dateCreatedFields['preset'] : '';
        if (!empty($dateCreatedPreset) and $creationDateFilter['access']) {
            $hasFilersFilled = true;
            $options = ['7d' => '7 DAY', '30d' => '30 DAY', '3m' => '3 MONTH', '6m' => '6 MONTH', '12m' => '12 MONTH'];
            $dateCreatedClause = '';
            if (isset($options[$dateCreatedPreset])) {
                $dateCreatedClause = 'date_creation > SUBDATE(NOW(), INTERVAL ' . $options[$dateCreatedPreset] . ')';
            } elseif ('custom' == $dateCreatedPreset and isset($dateCreatedFields['custom'])) {
                $dateCreatedSubclauses = [];
                $dateCreatedCustom = $dateCreatedFields['custom'];
                $customDates = array_flip($dateCreatedCustom);
                foreach (array_keys($customDates) as $customDate) {
                    $begin = $end = null;
                    $ymd   = substr($customDate, 0, 1);
                    if ('y' == $ymd) {
                        $year = substr($customDate, 1);
                        $begin = $year . '-01-01 00:00:00';
                        $end   = $year . '-12-31 23:59:59';
                    } elseif ('m' == $ymd) {
                        [$year, $month] = explode('-', substr($customDate, 1));
                        if (!isset($customDates['y' . $year])) {
                            $begin = $year . '-' . $month . '-01 00:00:00';
                            $end   = $year . '-' . $month . '-' . cal_days_in_month(CAL_GREGORIAN, (int) $month, (int) $year) . ' 23:59:59';
                        }
                    } elseif ('d' == $ymd) {
                        [$year, $month, $day] = explode('-', substr($customDate, 1));
                        if (!isset($customDates['y' . $year]) and !isset($customDates['m' . $year . '-' . $month])) {
                            $begin = $year . '-' . $month . '-' . $day . ' 00:00:00';
                            $end   = $year . '-' . $month . '-' . $day . ' 23:59:59';
                        }
                    }
                    if (!empty($begin) && $end !== null) {
                        $dateCreatedSubclauses[] = 'date_creation BETWEEN "' . $begin . '" AND "' . $end . '"';
                    }
                }
                $dateCreatedClause = '(' . implode(' OR ', StringUtil::prependAppendArrayItems($dateCreatedSubclauses, '(', ')')) . ')';
            }
            $imageIdsForFilter['date_created'] = $filterImageIds($dateCreatedClause);
        }

        // ratios
        /** @var list<string> $ratiosList */
        $ratiosList = is_array($searchFields['ratios'] ?? null) ? $searchFields['ratios'] : [];
        if (!empty($searchFields['ratios']) and $ratioFilter['access']) {
            $hasFilersFilled = true;
            $clauseForRatio  = ['Portrait' => 'width/height < 0.95', 'square' => 'width/height BETWEEN 0.95 AND 1.05', 'Landscape' => '(width/height > 1.05 AND width/height < 2)', 'Panorama' => 'width/height >= 2'];
            $ratiosClauses   = [];
            foreach ($ratiosList as $r) {
                $ratiosClauses[] = $clauseForRatio[$r] ?? '1=1';
            }
            $imageIdsForFilter['ratios'] = $filterImageIds('(' . implode(' OR ', $ratiosClauses) . ')');
        }

        // ratings
        /** @var list<int|string> $ratingsList */
        $ratingsList = is_array($searchFields['ratings'] ?? null) ? $searchFields['ratings'] : [];
        if (Config::rateEnabled() and !empty($searchFields['ratings']) and $ratingFilter['access']) {
            $hasFilersFilled = true;
            $filterClauses   = [];
            foreach ($ratingsList as $r) {
                $rInt = (int) $r;
                $filterClauses[] = 0 === $rInt ? 'rating_score IS NULL' : '(rating_score >= ' . ($rInt - 1) . ' AND rating_score < ' . $rInt . ')';
            }
            $imageIdsForFilter['ratings'] = $filterImageIds('(' . implode(' OR ', $filterClauses) . ')');
        }

        // filesize
        $filesizeMin = is_numeric($searchFields['filesize_min'] ?? null) ? (int) $searchFields['filesize_min'] : 0;
        $filesizeMax = is_numeric($searchFields['filesize_max'] ?? null) ? (int) $searchFields['filesize_max'] : 0;
        if (!empty($searchFields['filesize_min']) and !empty($searchFields['filesize_max']) and $fileSizeFilter['access']) {
            $hasFilersFilled = true;
            $imageIdsForFilter['filesize'] = $filterImageIds('filesize BETWEEN ' . ($filesizeMin - 100) . ' AND ' . ($filesizeMax + 100));
        }

        // height
        $heightMin = is_numeric($searchFields['height_min'] ?? null) ? (int) $searchFields['height_min'] : 0;
        $heightMax = is_numeric($searchFields['height_max'] ?? null) ? (int) $searchFields['height_max'] : 0;
        if (!empty($searchFields['height_min']) and !empty($searchFields['height_max']) and $heightFilter['access']) {
            $hasFilersFilled = true;
            $imageIdsForFilter['height'] = $filterImageIds('height BETWEEN ' . $heightMin . ' AND ' . $heightMax);
        }

        // width
        $widthMin = is_numeric($searchFields['width_min'] ?? null) ? (int) $searchFields['width_min'] : 0;
        $widthMax = is_numeric($searchFields['width_max'] ?? null) ? (int) $searchFields['width_max'] : 0;
        if (!empty($searchFields['width_min']) and !empty($searchFields['width_max']) and $widthFilter['access']) {
            $hasFilersFilled = true;
            $imageIdsForFilter['width'] = $filterImageIds('width BETWEEN ' . $widthMin . ' AND ' . $widthMax);
        }

        // tags
        /** @var array{words?: list<int|string>, mode?: string} $tagsFields */
        $tagsFields = is_array($searchFields['tags'] ?? null) ? $searchFields['tags'] : [];
        /** @var list<int|string> $tagsWordsRaw */
        $tagsWordsRaw = is_array($tagsFields['words'] ?? null) ? $tagsFields['words'] : [];
        $tagsWords  = array_map(static fn (int|string $v): int => (int) $v, $tagsWordsRaw);
        $tagsMode   = is_string($tagsFields['mode'] ?? null) ? $tagsFields['mode'] : 'AND';
        if (isset($searchFields['tags']) and !empty($tagsWords) and $tagsFilter['access']) {
            $hasFilersFilled = true;
            $imageIdsForFilter['tags'] = $this->tagService->getImageIdsForTags($tagsWords, $tagsMode);
        }

        if (!empty($imagesWhere)) {
            $imageIdsForFilter['custom'] = $filterImageIds($imagesWhere);
        }

        $items = [];
        if (!empty($imageIdsForFilter)) {
            /** @var array<int, array<string>> $typedFilterValues */
            $typedFilterValues = array_map(
                /** @param array<mixed> $v */
                static fn (array $v): array => array_map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '', $v),
                array_values($imageIdsForFilter)
            );
            if (count($typedFilterValues) > 1) {
                $items = array_values(array_unique(array_intersect(...$typedFilterValues)));
            } else {
                $items = $typedFilterValues[0];
            }
        }

        $this->logger->debug('getRegularSearchResults ' . count($items) . ' items in $unsorted_items');

        if (count($items) > 1) {
            $items = $this->searchRepo->orderImageIds(
                array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $items)),
                $this->orderByService->buildOrderByClause(Config::orderBy()),
            );
        }

        $details = [
            'matching_cat_ids' => isset($matchingCatIds) ? array_values($matchingCatIds) : null,
            'matching_tag_ids' => isset($matchingTagIds) ? array_values($matchingTagIds) : null,
            'has_filters_filled' => $hasFilersFilled,
            'image_ids_for_filter' => $imageIdsForFilter,
        ];
        $this->searchDetails = $details;

        return [
            'items'          => $items,
            'search_details' => $details,
        ];
    }

    /**
     * @return array{0: string, 1: list<mixed>, 2: list<\Doctrine\DBAL\ArrayParameterType|\Doctrine\DBAL\ParameterType>}
     */
    public function getClauseForFilter(string $filterName): array
    {
        $otherFiltersItems = $this->getItemsForFilter($filterName);
        if (false === $otherFiltersItems) {
            $forbidden = $this->searchDetails['forbidden'] ?? null;
            if (is_array($forbidden) && isset($forbidden[0], $forbidden[1], $forbidden[2]) && is_string($forbidden[0]) && is_array($forbidden[1]) && is_array($forbidden[2])) {
                $sql = '1=1' . $forbidden[0];
                /**
                 * Psalm/MoreSpecificReturnType: cannot prove list-shape after array-element narrowing.
                 * @psalm-suppress LessSpecificReturnStatement
                 * @var array{0: string, 1: list<mixed>, 2: list<\Doctrine\DBAL\ArrayParameterType|\Doctrine\DBAL\ParameterType>} $tuple
                 */
                $tuple = [$sql, $forbidden[1], $forbidden[2]];
                return $tuple;
            }
            return ['1=1', [], []];
        }
        return ['image_id IN (' . implode(',', $otherFiltersItems) . ')', [], []];
    }

    /** @return array<int>|false */
    public function getItemsForFilter(string $filterName): array|false
    {
        $imageIdsForFilter   = is_array($this->searchDetails['image_ids_for_filter'] ?? null) ? $this->searchDetails['image_ids_for_filter'] : [];
        $otherFilters        = array_diff(array_keys($imageIdsForFilter), [$filterName]);

        if (empty($otherFilters)) {
            return false;
        }

        $cacheKey = md5(implode(',', $otherFilters));
        $cache    = is_array($this->searchDetails['getItemsForFilter'] ?? null) ? $this->searchDetails['getItemsForFilter'] : [];

        if (!isset($cache[$cacheKey])) {
            $functionStart = StringUtil::getMoment();
            $firstKey      = array_shift($otherFilters);
            $first         = $imageIdsForFilter[$firstKey] ?? [];
            $otherFiltersItems = is_array($first)
                ? array_values(array_filter($first, fn ($v): bool => is_int($v) || is_string($v)))
                : [];
            foreach ($otherFilters as $otherFilter) {
                $nextRaw           = is_array($imageIdsForFilter[$otherFilter] ?? null) ? $imageIdsForFilter[$otherFilter] : [];
                $next              = array_filter($nextRaw, fn (mixed $v): bool => is_int($v) || is_string($v));
                $otherFiltersItems = array_intersect($otherFiltersItems, $next);
            }
            $otherFiltersItems = array_values(array_unique($otherFiltersItems));
            $debugMsg  = '[getItemsForFilter] cache computed for ' . (count($otherFilters) + 1) . ' other filters';
            $debugMsg .= ' (' . count($otherFiltersItems) . ' items)';
            $debugMsg .= ', time = ' . StringUtil::getElapsedTime($functionStart, StringUtil::getMoment());
            $this->logger->debug($debugMsg);

            if (empty($otherFiltersItems)) {
                $otherFiltersItems = [-1];
            }

            $cache[$cacheKey] = $otherFiltersItems;
            $this->searchDetails['getItemsForFilter'] = $cache;
        }

        $rawCached = is_array($cache[$cacheKey]) ? $cache[$cacheKey] : [];
        $cached    = [];
        foreach ($rawCached as $v) {
            if (is_int($v) || is_string($v)) {
                $cached[] = (int) $v;
            }
        }
        return $cached;
    }

    /**
     * @param string[] $fields
     * @return list<non-falsy-string>
     */
    public function qsearchGetTextTokenSearchSql(QSingleToken $token, array $fields): array
    {
        $clauses  = [];
        $variants = array_merge([$token->term], $token->variants);
        $fts      = [];
        foreach ($variants as $variant) {
            $useFt = mb_strlen($variant) > 3;
            if ($token->modifier & QST_WILDCARD_BEGIN) {
                $useFt = false;
            }
            if (($token->modifier & (QST_QUOTED | QST_WILDCARD_END)) === (QST_QUOTED | QST_WILDCARD_END)) {
                $useFt = false;
            }
            if ($useFt) {
                $parts = preg_split('/[' . preg_quote('-\'!"#$%&()*+,./:;<=>?@[\]^`{|}~', '/') . ']+/', $variant);
                $max   = ($parts !== false) ? max(array_map(mb_strlen(...), $parts)) : 0;
                if ($max < 4) {
                    $useFt = false;
                }
            }
            if (!$useFt) {
                if ($this->useRegexpICU === null) {
                    $this->useRegexpICU = false;
                    $dbVersion = DbInfo::version();
                    if (!preg_match('/mariadb/i', $dbVersion) and version_compare($dbVersion, '8.0.4', '>')) {
                        $this->useRegexpICU = true;
                    }
                }
                $pre  = ($token->modifier & QST_WILDCARD_BEGIN) ? '' : ($this->useRegexpICU ? '\\\\b' : '[[:<:]]');
                $post = ($token->modifier & QST_WILDCARD_END) ? '' : ($this->useRegexpICU ? '\\\\b' : '[[:>:]]');
                foreach ($fields as $field) {
                    $clauses[] = $field . ' REGEXP \'' . $pre . addslashes(preg_quote($variant)) . $post . '\'';
                }
            } else {
                $ft = $variant;
                if ($token->modifier & QST_QUOTED) {
                    $ft = '"' . $ft . '"';
                }
                if ($token->modifier & QST_WILDCARD_END) {
                    $ft .= '*';
                }
                $fts[] = $ft;
            }
        }
        if (count($fts)) {
            $clauses[] = 'MATCH(' . implode(', ', $fields) . ') AGAINST( \'' . addslashes(implode(' ', $fts)) . '\' IN BOOLEAN MODE)';
        }
        return $clauses;
    }

    public function qsearchGetImages(QExpression $expr, QResults $qsr): void
    {
        $qsr->images_iids = array_fill(0, count($expr->stokens), []);
        $queryBaseWhere   = "WHERE\n";
        for ($i = 0; $i < count($expr->stokens); $i++) {
            $token      = $expr->stokens[$i];
            $scopeId    = isset($token->scope) ? $token->scope->id : 'photo';
            $tokenScope = $token->scope;
            $clauses    = [];
            $like       = addslashes($token->term);
            $like       = str_replace(['%', '_'], ['\\%', '\\_'], $like);
            $fileLike   = 'CONVERT(file, CHAR) LIKE \'%' . $like . '%\'';

            switch ($scopeId) {
                case 'photo':
                    $clauses[] = $fileLike;
                    $clauses   = array_merge($clauses, $this->qsearchGetTextTokenSearchSql($token, ['name', 'comment']));
                    break;
                case 'file':
                    $clauses[] = $fileLike;
                    break;
                case 'author':
                    if (strlen($token->term)) {
                        $clauses = array_merge($clauses, $this->qsearchGetTextTokenSearchSql($token, ['author']));
                    } elseif ($token->modifier & QST_WILDCARD) {
                        $clauses[] = 'author IS NOT NULL';
                    } else {
                        $clauses[] = 'author IS NULL';
                    }
                    break;
                case 'width':
                case 'height':
                    if ($tokenScope !== null) {
                        $clauses[] = $tokenScope->getSql($scopeId, $token);
                    }
                    break;
                case 'ratio':
                    if ($tokenScope !== null) {
                        $clauses[] = $tokenScope->getSql('width/height', $token);
                    }
                    break;
                case 'size':
                    if ($tokenScope !== null) {
                        $clauses[] = $tokenScope->getSql('width*height', $token);
                    }
                    break;
                case 'hits':
                    if ($tokenScope !== null) {
                        $clauses[] = $tokenScope->getSql('hit', $token);
                    }
                    break;
                case 'score':
                    if ($tokenScope !== null) {
                        $clauses[] = $tokenScope->getSql('rating_score', $token);
                    }
                    break;
                case 'filesize':
                    if ($tokenScope !== null) {
                        $clauses[] = $tokenScope->getSql('1024*filesize', $token);
                    }
                    break;
                case 'created':
                    if ($tokenScope !== null) {
                        $clauses[] = $tokenScope->getSql('date_creation', $token);
                    }
                    break;
                case 'posted':
                    if ($tokenScope !== null) {
                        $clauses[] = $tokenScope->getSql('date_available', $token);
                    }
                    break;
                case 'id':
                    if ($tokenScope !== null) {
                        $clauses[] = $tokenScope->getSql($scopeId, $token);
                    }
                    break;
                default:
                    $clausesEvent = new QsearchGetImagesSqlScopes($clauses, $token, $expr);
                    $this->dispatcher->dispatch($clausesEvent);
                    $clauses = $clausesEvent->clauses;
                    break;
            }
            if (!empty($clauses)) {
                $whereFragment = $queryBaseWhere . '(' . implode("\n OR ", array_filter($clauses, is_string(...))) . ')';
                $qsr->images_iids[$i] = $this->searchRepo->findQsearchImageIdsByWhere($whereFragment);
            }
        }
    }

    public function qsearchGetTags(QExpression $expr, QResults $qsr): void
    {
        $tokenTagIds = $qsr->tag_iids = array_fill(0, count($expr->stokens), []);
        $allTags     = [];

        for ($i = 0; $i < count($expr->stokens); $i++) {
            $token = $expr->stokens[$i];
            if (isset($token->scope) && 'tag' != $token->scope->id) {
                continue;
            }
            if (empty($token->term)) {
                continue;
            }
            $clauses = $this->qsearchGetTextTokenSearchSql($token, ['name']);
            foreach ($this->tagRepository->findTagsByTextClauses($clauses) as $tag) {
                $tagId               = $tag->id->value;
                $tokenTagIds[$i][]   = $tagId;
                $allTags[$tagId]     = $tag->toRow();
            }
        }

        for ($i = 0; $i < count($expr->stokens) - 1; $i++) {
            if ((strlen($expr->stokens[$i]->term) <= 3 || strlen($expr->stokens[$i + 1]->term) <= 3)
              && (($expr->stoken_modifiers[$i] & (QST_QUOTED | QST_WILDCARD)) == 0)
              && (($expr->stoken_modifiers[$i + 1] & (QST_BREAK | QST_QUOTED | QST_WILDCARD)) == 0)) {
                $common = array_intersect($tokenTagIds[$i], $tokenTagIds[$i + 1]);
                if (count($common)) {
                    $tokenTagIds[$i] = $tokenTagIds[$i + 1] = array_values($common);
                }
            }
        }

        $positiveIds = $notIds = [];
        for ($i = 0; $i < count($expr->stokens); $i++) {
            $tagIds = $tokenTagIds[$i];
            $token  = $expr->stokens[$i];

            if (!empty($tagIds)) {
                $qsr->tag_iids[$i] = $this->tagRepository->findDistinctImageIdsGroupedByTagIds(array_map(intval(...), $tagIds));
                if ($expr->stoken_modifiers[$i] & QST_NOT) {
                    $notIds = array_merge($notIds, $tagIds);
                } else {
                    if (strlen($token->term) > 2 || count($expr->stokens) == 1 || isset($token->scope) || ($token->modifier & (QST_WILDCARD | QST_QUOTED))) {
                        $positiveIds = array_merge($positiveIds, $tagIds);
                    }
                }
            } elseif (isset($token->scope) && 'tag' == $token->scope->id && strlen($token->term) == 0) {
                if ($token->modifier & QST_WILDCARD) {
                    $qsr->tag_iids[$i] = $this->tagRepository->findAllDistinctImageIds();
                } else {
                    $qsr->tag_iids[$i] = $this->tagRepository->findUntaggedImageIds();
                }
            }
        }

        $allTags = array_intersect_key($allTags, array_flip(array_diff($positiveIds, $notIds)));
        usort($allTags, $this->htmlService->tagAlphaCompare(...));
        foreach ($allTags as &$tag) {
            $tagRenderEvent = new RenderTagName(is_string($tag['name'] ?? null) ? $tag['name'] : '', $tag);
            $this->dispatcher->dispatch($tagRenderEvent);
            $tag['name']    = $tagRenderEvent->tagName;
        }
        $qsr->all_tags = $allTags;
        $qsr->tag_ids  = $tokenTagIds;
    }

    public function qsearchGetCategories(QExpression $expr, QResults $qsr): void
    {
        $userId        = CurrentUser::get()->id;
        $tokenCatIds   = $qsr->cat_iids = array_fill(0, count($expr->stokens), []);
        $allCats       = [];

        for ($i = 0; $i < count($expr->stokens); $i++) {
            $token = $expr->stokens[$i];
            if (isset($token->scope) && 'category' != $token->scope->id) {
                continue;
            }
            if (empty($token->term)) {
                continue;
            }
            $clauses = $this->qsearchGetTextTokenSearchSql($token, ['name', 'comment']);
            foreach ($this->categoryRepository->findCategoriesByTextClausesForUser($userId, $clauses) as $cat) {
                $catId             = $cat->id->value;
                $tokenCatIds[$i][] = $catId;
                $allCats[$catId]   = $cat;
            }
        }

        for ($i = 0; $i < count($expr->stokens) - 1; $i++) {
            if ((strlen($expr->stokens[$i]->term) <= 3 || strlen($expr->stokens[$i + 1]->term) <= 3)
              && (($expr->stoken_modifiers[$i] & (QST_QUOTED | QST_WILDCARD)) == 0)
              && (($expr->stoken_modifiers[$i + 1] & (QST_BREAK | QST_QUOTED | QST_WILDCARD)) == 0)) {
                $common = array_intersect($tokenCatIds[$i], $tokenCatIds[$i + 1]);
                if (count($common)) {
                    $tokenCatIds[$i] = $tokenCatIds[$i + 1] = array_values($common);
                }
            }
        }

        $positiveIds = $notIds = [];
        for ($i = 0; $i < count($expr->stokens); $i++) {
            $catIds = $tokenCatIds[$i];
            $token  = $expr->stokens[$i];

            if (!empty($catIds)) {
                if (Config::quickSearchIncludeSubAlbums()) {
                    $catIds = $this->categoryRepository->filterVisibleCategoryIdsForUser(
                        $userId,
                        array_values($this->categoryService->getSubcatIds($catIds)),
                    );
                }
                $qsr->cat_iids[$i] = $this->categoryRepository->findDistinctImageIdsGroupedByCategoryIds(array_map(intval(...), $catIds));
                if ($expr->stoken_modifiers[$i] & QST_NOT) {
                    $notIds = array_merge($notIds, $catIds);
                } else {
                    if (strlen($token->term) > 2 || count($expr->stokens) == 1 || isset($token->scope) || ($token->modifier & (QST_WILDCARD | QST_QUOTED))) {
                        $positiveIds = array_merge($positiveIds, $catIds);
                    }
                }
            } elseif (isset($token->scope) && 'category' == $token->scope->id && strlen($token->term) == 0) {
                if ($token->modifier & QST_WILDCARD) {
                    $qsr->cat_iids[$i] = $this->categoryRepository->findAllDistinctImageIds();
                } else {
                    $qsr->cat_iids[$i] = $this->categoryRepository->findUncategorizedImageIds();
                }
            }
        }

        $allCats = array_intersect_key($allCats, array_flip(array_diff($positiveIds, $notIds)));
        usort($allCats, static fn (CategoryNamePermalink $a, CategoryNamePermalink $b): int => strcmp(strtolower($a->name), strtolower($b->name)));
        $rendered = [];
        foreach ($allCats as $cat) {
            $row            = $cat->toRow();
            $catRenderEvent = new RenderCategoryName($cat->name, $row);
            $this->dispatcher->dispatch($catRenderEvent);
            $row['name']    = $catRenderEvent->categoryName;
            $rendered[]     = $row;
        }
        $qsr->all_cats = $rendered;
        $qsr->cat_ids  = $tokenCatIds;
    }

    /**
     * @param string[] $ignoredTerms
     * @return int[]
     */
    public function qsearchEval(QMultiToken $expr, QResults $qsr, bool &$qualifies, array &$ignoredTerms): array
    {
        $qualifies     = false;
        $ignoredTerms  = [];
        $ids = $notIds = [];
        $crtQualifies  = false;
        $crtIgnoredTerms = [];

        for ($i = 0; $i < count($expr->tokens); $i++) {
            $crt    = $expr->tokens[$i];
            $crtIds = [];
            if ($crt instanceof QSingleToken) {
                $crtIds       = $qsr->iids[$crt->idx] = array_unique(array_merge($qsr->images_iids[$crt->idx], $qsr->cat_iids[$crt->idx], $qsr->tag_iids[$crt->idx]));
                $crtQualifies = count($crtIds) > 0 || count($qsr->tag_ids[$crt->idx]) > 0;
                $crtIgnoredTerms = $crtQualifies ? [] : [(string) $crt];
            } else {
                $crtIds = $this->qsearchEval($crt, $qsr, $crtQualifies, $crtIgnoredTerms);
            }

            $modifier = $crt->modifier;
            if ($modifier & QST_NOT) {
                $notIds = array_unique(array_merge($notIds, $crtIds));
            } else {
                $ignoredTerms = array_merge($ignoredTerms, $crtIgnoredTerms);
                if ($modifier & QST_OR) {
                    $ids      = array_unique(array_merge($ids, $crtIds));
                    $qualifies = $qualifies || $crtQualifies;
                } elseif ($crtQualifies) {
                    if ($qualifies) {
                        $ids = array_intersect($ids, $crtIds);
                    } else {
                        $ids = $crtIds;
                    }
                    $qualifies = true;
                }
            }
        }

        if (count($notIds)) {
            $ids = array_diff($ids, $notIds);
        }
        return $ids;
    }

    /**
     * @param array<mixed> $options
     * @return array<mixed>|null
     */
    public function getQuickSearchResults(string $q, array $options): ?array
    {
        $currentUser = CurrentUser::get();
        $cacheUpdate = is_scalar($currentUser->rawAttributes['cache_update_time'] ?? null)
            ? (string) $currentUser->rawAttributes['cache_update_time']
            : '';

        $cacheKey = md5(implode('&', array_map(
            static fn (mixed $k): string => is_scalar($k) ? (string) $k : '',
            [
                strtolower($q),
                $this->orderByService->toCacheKey(Config::orderBy()),
                $currentUser->id, $cacheUpdate,
                isset($options['permissions']) ? (bool) $options['permissions'] : true,
                $options['images_where'] ?? '',
            ]
        )) . AppInfo::VERSION);

        $item = $this->pool->getItem($cacheKey);
        if ($item->isHit()) {
            $cachedRes = $item->get();
            return is_array($cachedRes) ? $cachedRes : null;
        }

        $res      = $this->getQuickSearchResultsNoCache($q, $options);
        $resItems = is_array($res['items'] ?? null) ? $res['items'] : [];
        if (count($resItems)) {
            $item->set($res);
            $item->expiresAfter(300);
            $this->pool->save($item);
        }
        return $res;
    }

    /**
     * @param array<mixed> $options
     * @return array<mixed>
     */
    public function getQuickSearchResultsNoCache(string $q, array $options): array
    {
        $q             = trim(stripslashes($q));
        $searchResults = ['items' => [], 'qs' => ['q' => $q]];
        $qEvent = new QsearchPre($q);
        $this->dispatcher->dispatch($qEvent);
        $q = $qEvent->query;

        $scopes   = [];
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
        $postedDateAliases  = ['added'];
        if (Config::calendarDatefield() == 'date_creation') {
            $createdDateAliases[] = 'date';
        } else {
            $postedDateAliases[] = 'date';
        }
        $scopes[] = new QDateRangeScope('created', $createdDateAliases, true);
        $scopes[] = new QDateRangeScope('posted', $postedDateAliases);

        $scopesEvent = new QsearchGetScopes($scopes);
        $this->dispatcher->dispatch($scopesEvent);
        /** @var array<QSearchScope> $scopes */
        $scopes = $scopesEvent->scopes;
        $expression = new QExpression($q, $scopes);

        $langCode = substr($this->userService->getDefaultLanguage(), 0, 2);
        $inflector = match ($langCode) {
            'en'    => new InflectorEn(),
            'fr'    => new InflectorFr(),
            default => null,
        };
        if ($inflector !== null) {
            foreach ($expression->stokens as $token) {
                if (isset($token->scope) && !$token->scope->is_text) {
                    continue;
                }
                if (strlen($token->term) > 2
                  && ($token->modifier & (QST_QUOTED | QST_WILDCARD)) == 0
                  && strcspn($token->term, '\'0123456789') == strlen($token->term)) {
                    $token->variants = array_unique(array_diff($inflector->getVariants($token->term), [$token->term]));
                }
            }
        }

        $this->dispatcher->dispatch(new QsearchExpressionParsed($expression));

        if (count($expression->stokens) == 0) {
            return $searchResults;
        }
        $qsr = new QResults();
        $this->qsearchGetTags($expression, $qsr);
        $this->qsearchGetCategories($expression, $qsr);
        $this->qsearchGetImages($expression, $qsr);

        $this->dispatcher->dispatch(new QsearchBeforeEval($expression, $qsr));

        $tmp   = false;
        $searchResults['qs']['unmatched_terms'] = [];
        $ids   = $this->qsearchEval($expression, $qsr, $tmp, $searchResults['qs']['unmatched_terms']);

        $debug   = [];
        $debug[] = "<!--\nparsed: " . htmlspecialchars((string) $expression);
        $debug[] = count($expression->stokens) . ' tokens';
        for ($i = 0; $i < count($expression->stokens); $i++) {
            $debug[] = htmlspecialchars((string) $expression->stokens[$i]) . ': '
              . count($qsr->tag_ids[$i]) . ' tags, '
              . count($qsr->tag_iids[$i]) . ' tiids, '
              . count($qsr->images_iids[$i]) . ' iiids, '
              . count($qsr->iids[$i]) . ' iids'
              . ' modifier:' . dechex($expression->stoken_modifiers[$i])
              . (!empty($expression->stokens[$i]->variants) ? ' variants: ' . htmlspecialchars(implode(', ', $expression->stokens[$i]->variants)) : '');
        }
        $debug[] = 'before perms ' . count($ids);

        $searchResults['qs']['matching_tags'] = $qsr->all_tags;
        $searchResults['qs']['matching_cats'] = $qsr->all_cats;
        $resultsEvent = new QsearchResults($searchResults, $expression, $qsr);
        $this->dispatcher->dispatch($resultsEvent);
        $searchResults = $resultsEvent->results;
        $rawItems      = is_array($searchResults['items'] ?? null) ? $searchResults['items'] : [];
        $extraItems    = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rawItems);
        $ids           = array_merge($ids, $extraItems);

        if (empty($ids)) {
            $debug[] = '-->';
            TemplateRegistry::current()->append('footer_elements', implode("\n", $debug));
            return $searchResults;
        }

        $permissionsRaw = !isset($options['permissions']) ? true : $options['permissions'];
        $permissions   = $permissionsRaw === true || $permissionsRaw === 1 || $permissionsRaw === '1';
        $whereClauses  = [];
        $whereClauses[] = 'i.id IN (' . implode(',', $ids) . ')';
        if (isset($options['images_where']) && $options['images_where'] !== '') {
            $whereClauses[] = '(' . (is_string($options['images_where']) ? $options['images_where'] : '') . ')';
        }
        $permParams = [];
        $permTypes  = [];
        if ($permissions) {
            [$permSql, $permParams, $permTypes] = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'category_id', 'forbidden_images' => 'i.id'], null, true);
            $whereClauses[] = $permSql;
        }

        $ids = $this->searchRepo->findOrderedImageIdsForQsearch(
            $whereClauses,
            $permissions,
            $permParams,
            $permTypes,
            $this->orderByService->buildOrderByClause(Config::orderBy()),
        );

        $debug[] = count($ids) . ' final photo count -->';
        TemplateRegistry::current()->append('footer_elements', implode("\n", $debug));

        $searchResults['items'] = $ids;
        return $searchResults;
    }

    /** @return array<mixed> */
    public function getSearchResults(string $searchId, bool $superOrderBy): array
    {
        $search = $this->getSearchArray($searchId);
        if (!isset($search['q'])) {
            return $this->getRegularSearchResults($search, '');
        } else {
            $searchQ = is_string($search['q']) ? $search['q'] : '';
            return $this->getQuickSearchResults($searchQ, ['super_order_by' => $superOrderBy, 'images_where' => '']) ?? [];
        }
    }

    /** @return string[]|null */
    public function splitAllwords(string $rawAllwords): ?array
    {
        $words      = null;
        $rawAllwords = trim($rawAllwords, " \n\r\t\v\x00.");
        if (!preg_match('/^\s*$/', $rawAllwords)) {
            $dropCharMatch   = [';','&','(',')','<','>','`','\'','"','|',',','@','?','%','. ','[',']','{','}',':','\\','/','=','\'','!','*'];
            $dropCharReplace = [' ',' ',' ',' ',' ',' ', '', '', ' ',' ',' ',' ',' ',' ',' ' ,' ',' ',' ',' ',' ','' , ' ',' ',' ', ' ',' '];
            $split   = preg_split('/\s+/', str_replace($dropCharMatch, $dropCharReplace, $rawAllwords));
            $words   = $split !== false ? array_unique($split) : [];
        }
        return $words;
    }

    public function getAvailableSearchUuid(): string
    {
        $candidate = 'psk-' . date('Ymd') . '-' . StringUtil::generateKey(10);
        $counter   = $this->searchRepo->countByUuid($candidate);
        if (0 == $counter) {
            return $candidate;
        } else {
            return $this->getAvailableSearchUuid();
        }
    }

    /**
     * @param array<mixed> $rules
     * @return array<mixed>
     */
    public function saveSearch(array $rules, int|string|null $forkedFrom = null): array
    {
        $createdBy  = CurrentUser::get()->id;
        $dbnow      = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        $searchUuid = $this->getAvailableSearchUuid();

        $this->searchRepo->insertSearchRow([
            'rules'       => json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_on'  => $dbnow,
            'created_by'  => $createdBy,
            'search_uuid' => $searchUuid,
            'forked_from' => $forkedFrom,
        ]);

        if (!$this->permissionService->isAGuest() and !$this->permissionService->isGeneric()) {
            $rulesFields = is_array($rules['fields'] ?? null) ? $rules['fields'] : [];
            $this->preferencesService->userprefsUpdateParam('gallery_search_filters', array_keys($rulesFields));
        }

        $url = $this->urlService->makeIndexUrl(['section' => 'search', 'search' => $searchUuid]);

        return [$searchUuid, $url];
    }
}
