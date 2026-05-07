<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Doctrine\DBAL\Connection;
use Piwigo\Cache\PersistentCacheRegistry;
use Piwigo\Config\Config;
use Piwigo\Db\DbInfo;
use Piwigo\Exception\ValidationException;
use Piwigo\Search\Inflector\InflectorEn;
use Piwigo\Search\Inflector\InflectorFr;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserService;
use Psr\Log\LoggerInterface;

final readonly class SearchService
{
    public function __construct(
        private SearchRepository $searchRepo,
        private Connection $conn,
        private LoggerInterface $logger,
    ) {
    }

    public function getSearchIdPattern(int|string $candidate): ?string
    {
        $clause_pattern = null;
        if (preg_match('/^psk-\d{8}-[a-z0-9]{10}$/i', (string) $candidate)) {
            $clause_pattern = 'search_uuid = \'%s\'';
        } elseif (preg_match('/^\d+$/', (string) $candidate)) {
            $clause_pattern = 'id = %u';
        }
        return $clause_pattern;
    }

    /** @return array<string,mixed>|null */
    public function getSearchInfo(int|string $candidate): ?array
    {
        $page = &$GLOBALS['page'];
        if (!is_array($page)) {
            $page = [];
        }
        $clausePattern = $this->getSearchIdPattern($candidate);

        if (empty($clausePattern)) {
            throw new ValidationException('Invalid search identifier');
        }

        $query   = 'SELECT * FROM ' . SEARCH_TABLE . ' WHERE ' . sprintf($clausePattern, $candidate) . ';';
        $searches = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();

        if (count($searches) > 0) {
            if (script_basename() != 'ws' and 'id = %u' == $clausePattern and isset($searches[0]['search_uuid'])) {
                fatal_error('this search is not reachable with its id, need the search_uuid instead');
            }
            if (isset($page['section']) and 'search' == $page['section']) {
                $page['search_id'] = $searches[0]['id'];
            }
            return $searches[0];
        }

        return null;
    }

    /** @return array<mixed> */
    public function getSearchArray(mixed $searchId): array
    {
        $search = $this->getSearchInfo(is_int($searchId) ? $searchId : (is_scalar($searchId) ? (string) $searchId : ''));
        if (empty($search)) {
            bad_request('this search identifier does not exist');
        }
        $rules  = $search['rules'] ?? '';
        $result = unserialize(is_string($rules) ? $rules : '');
        return is_array($result) ? $result : [];
    }

    /**
     * @param array<mixed> $search
     * @return array<mixed>
     */
    public function getRegularSearchResults(array $search, ?string $imagesWhere = ''): array
    {
        $this->logger->debug('getRegularSearchResults', $search);

        $hasFilersFilled = false;

        $forbidden = PermissionService::get()->getSqlConditionFandF(
            ['forbidden_categories' => 'category_id', 'visible_categories' => 'category_id', 'visible_images' => 'id'],
            "\n  AND"
        );

        $imageIdsForFilter = [];

        $displayFilters = safe_unserialize(Config::filtersViews() ?? '');

        foreach ($displayFilters as $filtName => $filtConf) {
            $filtConf = is_array($filtConf) ? $filtConf : [];
            if (isset($filtConf['access'])) {
                $access      = is_scalar($filtConf['access']) ? (string) $filtConf['access'] : '';
                $filtNameStr = (string) $filtName;
                $filtEntry   = is_array($displayFilters[$filtNameStr] ?? null) ? (array) $displayFilters[$filtNameStr] : [];
                if ($access == 'everybody' or ($access == 'admins-only' and PermissionService::get()->isAdmin()) or ($access == 'registered-users' and PermissionService::get()->isClassicUser())) {
                    $filtEntry['access'] = true;
                } else {
                    $filtEntry['access'] = false;
                }
                $displayFilters[$filtNameStr] = $filtEntry;
            }
        }

        $expertFilter       = is_array($displayFilters['expert'] ?? null) ? (array) $displayFilters['expert'] : [];
        $allwordsFilter     = is_array($displayFilters['words'] ?? null) ? (array) $displayFilters['words'] : [];
        $authorFilter       = is_array($displayFilters['author'] ?? null) ? (array) $displayFilters['author'] : [];
        $filetypeFilter     = is_array($displayFilters['file_type'] ?? null) ? (array) $displayFilters['file_type'] : [];
        $addedByFilter      = is_array($displayFilters['added_by'] ?? null) ? (array) $displayFilters['added_by'] : [];
        $albumFilter        = is_array($displayFilters['album'] ?? null) ? (array) $displayFilters['album'] : [];
        $postDateFilter     = is_array($displayFilters['post_date'] ?? null) ? (array) $displayFilters['post_date'] : [];
        $creationDateFilter = is_array($displayFilters['creation_date'] ?? null) ? (array) $displayFilters['creation_date'] : [];
        $ratioFilter        = is_array($displayFilters['ratio'] ?? null) ? (array) $displayFilters['ratio'] : [];
        $ratingFilter       = is_array($displayFilters['rating'] ?? null) ? (array) $displayFilters['rating'] : [];
        $fileSizeFilter     = is_array($displayFilters['file_size'] ?? null) ? (array) $displayFilters['file_size'] : [];
        $heightFilter       = is_array($displayFilters['height'] ?? null) ? (array) $displayFilters['height'] : [];
        $widthFilter        = is_array($displayFilters['width'] ?? null) ? (array) $displayFilters['width'] : [];
        $tagsFilter         = is_array($displayFilters['tags'] ?? null) ? (array) $displayFilters['tags'] : [];

        $searchFields = is_array($search['fields'] ?? null) ? $search['fields'] : [];

        $expertFields = is_array($searchFields['expert'] ?? null) ? $searchFields['expert'] : [];
        if (isset($searchFields['expert']) and !empty($expertFields['string']) and $expertFilter['access']) {
            $hasFilersFilled = true;
            $expertString = is_scalar($expertFields['string']) ? (string) $expertFields['string'] : '';
            $expertQsr    = $this->getQuickSearchResults($expertString, []) ?? [];
            $imageIdsForFilter['expert'] = is_array($expertQsr['items'] ?? null) ? $expertQsr['items'] : [];
        }

        $allwordsFields = is_array($searchFields['allwords'] ?? null) ? $searchFields['allwords'] : [];
        if (isset($searchFields['allwords']) and !empty($allwordsFields['words']) and count(is_array($allwordsFields['fields'] ?? null) ? $allwordsFields['fields'] : []) > 0 and ($allwordsFilter['access'] ?? false)) {
            $hasFilersFilled = true;
            $fields = ['file', 'name', 'comment', 'author'];
            $allwordsFieldList = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', is_array($allwordsFields['fields'] ?? null) ? $allwordsFields['fields'] : []);
            if (isset($allwordsFields['fields'])) {
                $fields = array_intersect($fields, $allwordsFieldList);
            }
            $catFieldsDictionary = ['cat-title' => 'name', 'cat-desc' => 'comment'];
            $catFields           = array_intersect(array_keys($catFieldsDictionary), $allwordsFieldList);
            $wordClauses         = [];
            $catIdsByWord = $tagIdsByWord = [];
            $allwordsWords = is_array($allwordsFields['words']) ? $allwordsFields['words'] : [];
            foreach ($allwordsWords as $word) {
                $word         = is_scalar($word) ? (string) $word : '';
                $fieldClauses = [];
                foreach ($fields as $field) {
                    $fieldClauses[] = $field . " LIKE '%" . $word . "%'";
                }
                if (count($catFields) > 0) {
                    $catWordClauses = [];
                    $catFieldClauses = [];
                    foreach ($catFields as $catField) {
                        $catFieldClauses[] = $catFieldsDictionary[$catField] . " LIKE '%" . $word . "%'";
                    }
                    $catWordClauses[] = implode(' OR ', $catFieldClauses);
                    $catIds = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . CATEGORIES_TABLE . ' WHERE ' . implode(' OR ', $catWordClauses) . ';')->fetchAllAssociative(), 'id'));
                    $catIdsByWord[$word] = $catIds;
                    if (count($catIds) > 0) {
                        $catImageIds = array_column(get_dbal_connection()->executeQuery('SELECT image_id FROM ' . IMAGE_CATEGORY_TABLE . ' WHERE category_id IN (' . implode(',', $catIds) . ');')->fetchAllAssociative(), 'image_id');
                        if (count($catImageIds) > 0) {
                            $fieldClauses[] = 'id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $catImageIds)) . ')';
                        }
                    }
                }
                if (in_array('tags', $allwordsFieldList)) {
                    $tagIds = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . TAGS_TABLE . ' WHERE name LIKE \'%' . $word . '%\';')->fetchAllAssociative(), 'id'));
                    $tagIdsByWord[$word] = $tagIds;
                    if (count($tagIds) > 0) {
                        $tagImageIds = array_column(get_dbal_connection()->executeQuery('SELECT image_id FROM ' . IMAGE_TAG_TABLE . ' WHERE tag_id IN (' . implode(',', $tagIds) . ');')->fetchAllAssociative(), 'image_id');
                        if (count($tagImageIds) > 0) {
                            $fieldClauses[] = 'id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $tagImageIds)) . ')';
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
            $allwordsMode = is_scalar($allwordsFields['mode'] ?? null) ? (string) $allwordsFields['mode'] : 'AND';
            if (!in_array($allwordsMode, ['OR', 'AND'])) {
                $allwordsMode = 'AND';
            }
            $filterClause = "\n         " . implode("\n         " . $allwordsMode . "\n         ", $wordClauses);
            $imageIdsForFilter['allwords'] = array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT(id) FROM ' . IMAGES_TABLE . ' AS i INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id WHERE ' . $filterClause . ' ' . $forbidden . ';')->fetchAllAssociative(), 'id');

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

        $authorFields = is_array($searchFields['author'] ?? null) ? $searchFields['author'] : [];
        $authorWords  = is_array($authorFields['words'] ?? null) ? $authorFields['words'] : [];
        if (isset($searchFields['author']) and count($authorWords) > 0 and $authorFilter['access']) {
            $hasFilersFilled  = true;
            $authorClauses = [];
            foreach ($authorWords as $word) {
                $authorClauses[] = "author = '" . (is_scalar($word) ? (string) $word : '') . "'";
            }
            $imageIdsForFilter['author'] = array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT(id) FROM ' . IMAGES_TABLE . ' AS i INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id WHERE (' . implode(' OR ', $authorClauses) . ') ' . $forbidden . ';')->fetchAllAssociative(), 'id');
        }

        $filetypesList = is_array($searchFields['filetypes'] ?? null) ? $searchFields['filetypes'] : [];
        if (!empty($searchFields['filetypes']) and $filetypeFilter['access']) {
            $hasFilersFilled = true;
            $filetypesClauses = [];
            foreach ($filetypesList as $ext) {
                $filetypesClauses[] = 'path LIKE \'%.' . (is_scalar($ext) ? (string) $ext : '') . '\'';
            }
            $imageIdsForFilter['filetypes'] = array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT(id) FROM ' . IMAGES_TABLE . ' AS i INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id WHERE (' . implode(' OR ', $filetypesClauses) . ') ' . $forbidden . ';')->fetchAllAssociative(), 'id');
        }

        $addedByList = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', is_array($searchFields['added_by'] ?? null) ? $searchFields['added_by'] : []);
        if (!empty($searchFields['added_by']) and $addedByFilter['access']) {
            $hasFilersFilled = true;
            $imageIdsForFilter['added_by'] = array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT(id) FROM ' . IMAGES_TABLE . ' AS i INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id WHERE added_by IN (' . implode(',', $addedByList) . ') ' . $forbidden . ';')->fetchAllAssociative(), 'id');
        }

        $catFieldsData = is_array($searchFields['cat'] ?? null) ? $searchFields['cat'] : [];
        $catWords      = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', is_array($catFieldsData['words'] ?? null) ? $catFieldsData['words'] : []);
        if (isset($searchFields['cat']) and !empty($catWords) and $albumFilter['access']) {
            $hasFilersFilled = true;
            $catSubInc = !empty($catFieldsData['sub_inc']);
            $catIds    = $catSubInc ? get_subcat_ids($catWords) : $catWords;
            if (!empty($catIds)) {
                $imageIdsForFilter['cat'] = array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT(id) FROM ' . IMAGES_TABLE . ' AS i INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id WHERE category_id IN (' . implode(',', $catIds) . ') ' . $forbidden . ';')->fetchAllAssociative(), 'id');
            }
        }

        // date_posted
        $datePostedFields = is_array($searchFields['date_posted'] ?? null) ? $searchFields['date_posted'] : [];
        $datePostedPreset = is_scalar($datePostedFields['preset'] ?? null) ? (string) $datePostedFields['preset'] : '';
        if (!empty($datePostedPreset) and $postDateFilter['access']) {
            $hasFilersFilled = true;
            $options = ['24h' => '24 HOUR', '7d' => '7 DAY', '30d' => '30 DAY', '3m' => '3 MONTH', '6m' => '6 MONTH'];
            $datePostedClause = '';
            if (isset($options[$datePostedPreset])) {
                $datePostedClause = 'date_available > SUBDATE(NOW(), INTERVAL ' . $options[$datePostedPreset] . ')';
            } elseif ('custom' == $datePostedPreset and isset($datePostedFields['custom'])) {
                $datePostedSubclauses = [];
                $datePostedCustom = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', is_array($datePostedFields['custom']) ? $datePostedFields['custom'] : []);
                $customDates = array_flip($datePostedCustom);
                foreach (array_keys($customDates) as $customDate) {
                    $begin = $end = null;
                    $ymd   = substr((string) $customDate, 0, 1);
                    if ('y' == $ymd) {
                        $year = substr((string) $customDate, 1);
                        $begin = $year . '-01-01 00:00:00';
                        $end   = $year . '-12-31 23:59:59';
                    } elseif ('m' == $ymd) {
                        [$year, $month] = explode('-', substr((string) $customDate, 1));
                        if (!isset($customDates['y' . $year])) {
                            $begin = $year . '-' . $month . '-01 00:00:00';
                            $end   = $year . '-' . $month . '-' . cal_days_in_month(CAL_GREGORIAN, (int) $month, (int) $year) . ' 23:59:59';
                        }
                    } elseif ('d' == $ymd) {
                        [$year, $month, $day] = explode('-', substr((string) $customDate, 1));
                        if (!isset($customDates['y' . $year]) and !isset($customDates['m' . $year . '-' . $month])) {
                            $begin = $year . '-' . $month . '-' . $day . ' 00:00:00';
                            $end   = $year . '-' . $month . '-' . $day . ' 23:59:59';
                        }
                    }
                    if (!empty($begin)) {
                        $datePostedSubclauses[] = 'date_available BETWEEN "' . $begin . '" AND "' . $end . '"';
                    }
                }
                $datePostedClause = '(' . implode(' OR ', prepend_append_array_items($datePostedSubclauses, '(', ')')) . ')';
            }
            $imageIdsForFilter['date_posted'] = array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT(id) FROM ' . IMAGES_TABLE . ' AS i INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id WHERE ' . $datePostedClause . ' ' . $forbidden . ';')->fetchAllAssociative(), 'id');
        }

        // date_created
        $dateCreatedFields = is_array($searchFields['date_created'] ?? null) ? $searchFields['date_created'] : [];
        $dateCreatedPreset = is_scalar($dateCreatedFields['preset'] ?? null) ? (string) $dateCreatedFields['preset'] : '';
        if (!empty($dateCreatedPreset) and $creationDateFilter['access']) {
            $hasFilersFilled = true;
            $options = ['7d' => '7 DAY', '30d' => '30 DAY', '3m' => '3 MONTH', '6m' => '6 MONTH', '12m' => '12 MONTH'];
            $dateCreatedClause = '';
            if (isset($options[$dateCreatedPreset])) {
                $dateCreatedClause = 'date_creation > SUBDATE(NOW(), INTERVAL ' . $options[$dateCreatedPreset] . ')';
            } elseif ('custom' == $dateCreatedPreset and isset($dateCreatedFields['custom'])) {
                $dateCreatedSubclauses = [];
                $dateCreatedCustom = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', is_array($dateCreatedFields['custom']) ? $dateCreatedFields['custom'] : []);
                $customDates = array_flip($dateCreatedCustom);
                foreach (array_keys($customDates) as $customDate) {
                    $begin = $end = null;
                    $ymd   = substr((string) $customDate, 0, 1);
                    if ('y' == $ymd) {
                        $year = substr((string) $customDate, 1);
                        $begin = $year . '-01-01 00:00:00';
                        $end   = $year . '-12-31 23:59:59';
                    } elseif ('m' == $ymd) {
                        [$year, $month] = explode('-', substr((string) $customDate, 1));
                        if (!isset($customDates['y' . $year])) {
                            $begin = $year . '-' . $month . '-01 00:00:00';
                            $end   = $year . '-' . $month . '-' . cal_days_in_month(CAL_GREGORIAN, (int) $month, (int) $year) . ' 23:59:59';
                        }
                    } elseif ('d' == $ymd) {
                        [$year, $month, $day] = explode('-', substr((string) $customDate, 1));
                        if (!isset($customDates['y' . $year]) and !isset($customDates['m' . $year . '-' . $month])) {
                            $begin = $year . '-' . $month . '-' . $day . ' 00:00:00';
                            $end   = $year . '-' . $month . '-' . $day . ' 23:59:59';
                        }
                    }
                    if (!empty($begin)) {
                        $dateCreatedSubclauses[] = 'date_creation BETWEEN "' . $begin . '" AND "' . $end . '"';
                    }
                }
                $dateCreatedClause = '(' . implode(' OR ', prepend_append_array_items($dateCreatedSubclauses, '(', ')')) . ')';
            }
            $imageIdsForFilter['date_created'] = array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT(id) FROM ' . IMAGES_TABLE . ' AS i INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id WHERE ' . $dateCreatedClause . ' ' . $forbidden . ';')->fetchAllAssociative(), 'id');
        }

        // ratios
        $ratiosList = is_array($searchFields['ratios'] ?? null) ? $searchFields['ratios'] : [];
        if (!empty($searchFields['ratios']) and $ratioFilter['access']) {
            $hasFilersFilled = true;
            $clauseForRatio  = ['Portrait' => 'width/height < 0.95', 'square' => 'width/height BETWEEN 0.95 AND 1.05', 'Landscape' => '(width/height > 1.05 AND width/height < 2)', 'Panorama' => 'width/height >= 2'];
            $ratiosClauses   = [];
            foreach ($ratiosList as $r) {
                $ratiosClauses[] = $clauseForRatio[is_scalar($r) ? (string) $r : ''] ?? '1=1';
            }
            $imageIdsForFilter['ratios'] = array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT(id) FROM ' . IMAGES_TABLE . ' AS i INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id WHERE (' . implode(' OR ', $ratiosClauses) . ') ' . $forbidden . ';')->fetchAllAssociative(), 'id');
        }

        // ratings
        $ratingsList = is_array($searchFields['ratings'] ?? null) ? $searchFields['ratings'] : [];
        if (Config::rateEnabled() and !empty($searchFields['ratings']) and $ratingFilter['access']) {
            $hasFilersFilled = true;
            $filterClauses   = [];
            foreach ($ratingsList as $r) {
                $r = is_scalar($r) ? (int) $r : 0;
                $filterClauses[] = 0 == $r ? 'rating_score IS NULL' : '(rating_score >= ' . ($r - 1) . ' AND rating_score < ' . $r . ')';
            }
            $imageIdsForFilter['ratings'] = array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT(id) FROM ' . IMAGES_TABLE . ' AS i INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id WHERE (' . implode(' OR ', $filterClauses) . ') ' . $forbidden . ';')->fetchAllAssociative(), 'id');
        }

        // filesize
        $filesizeMin = is_numeric($searchFields['filesize_min'] ?? null) ? (int) $searchFields['filesize_min'] : 0;
        $filesizeMax = is_numeric($searchFields['filesize_max'] ?? null) ? (int) $searchFields['filesize_max'] : 0;
        if (!empty($searchFields['filesize_min']) and !empty($searchFields['filesize_max']) and $fileSizeFilter['access']) {
            $hasFilersFilled = true;
            $imageIdsForFilter['filesize'] = array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT(id) FROM ' . IMAGES_TABLE . ' AS i INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id WHERE filesize BETWEEN ' . ($filesizeMin - 100) . ' AND ' . ($filesizeMax + 100) . ' ' . $forbidden . ';')->fetchAllAssociative(), 'id');
        }

        // height
        $heightMin = is_numeric($searchFields['height_min'] ?? null) ? (int) $searchFields['height_min'] : 0;
        $heightMax = is_numeric($searchFields['height_max'] ?? null) ? (int) $searchFields['height_max'] : 0;
        if (!empty($searchFields['height_min']) and !empty($searchFields['height_max']) and $heightFilter['access']) {
            $hasFilersFilled = true;
            $imageIdsForFilter['height'] = array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT(id) FROM ' . IMAGES_TABLE . ' AS i INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id WHERE height BETWEEN ' . $heightMin . ' AND ' . $heightMax . ' ' . $forbidden . ';')->fetchAllAssociative(), 'id');
        }

        // width
        $widthMin = is_numeric($searchFields['width_min'] ?? null) ? (int) $searchFields['width_min'] : 0;
        $widthMax = is_numeric($searchFields['width_max'] ?? null) ? (int) $searchFields['width_max'] : 0;
        if (!empty($searchFields['width_min']) and !empty($searchFields['width_max']) and $widthFilter['access']) {
            $hasFilersFilled = true;
            $imageIdsForFilter['width'] = array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT(id) FROM ' . IMAGES_TABLE . ' AS i INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id WHERE width BETWEEN ' . $widthMin . ' AND ' . $widthMax . ' ' . $forbidden . ';')->fetchAllAssociative(), 'id');
        }

        // tags
        $tagsFields = is_array($searchFields['tags'] ?? null) ? $searchFields['tags'] : [];
        $tagsWords  = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($tagsFields['words'] ?? null) ? $tagsFields['words'] : []);
        $tagsMode   = is_scalar($tagsFields['mode'] ?? null) ? (string) $tagsFields['mode'] : 'AND';
        if (isset($searchFields['tags']) and !empty($tagsWords) and $tagsFilter['access']) {
            $hasFilersFilled = true;
            $imageIdsForFilter['tags'] = get_image_ids_for_tags($tagsWords, $tagsMode);
        }

        if (!empty($imagesWhere)) {
            $imageIdsForFilter['custom'] = array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT(id) FROM ' . IMAGES_TABLE . ' AS i INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id WHERE ' . $imagesWhere . ' ' . $forbidden . ';')->fetchAllAssociative(), 'id');
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
            $items = array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . IMAGES_TABLE . ' i WHERE id IN (' . implode(',', $items) . ') ' . Config::orderBy())->fetchAllAssociative(), 'id');
        }

        return [
            'items' => $items,
            'search_details' => [
                'matching_cat_ids' => isset($matchingCatIds) ? array_values($matchingCatIds) : null,
                'matching_tag_ids' => isset($matchingTagIds) ? array_values($matchingTagIds) : null,
                'has_filters_filled' => $hasFilersFilled,
                'image_ids_for_filter' => $imageIdsForFilter,
            ],
        ];
    }

    public function getClauseForFilter(mixed $filterName): string
    {
        $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
        $otherFiltersItems = $this->getItemsForFilter(is_scalar($filterName) ? (string) $filterName : '');
        if (false === $otherFiltersItems) {
            $details  = is_array($page['search_details'] ?? null) ? $page['search_details'] : [];
            $forbidden = is_string($details['forbidden'] ?? null) ? $details['forbidden'] : '';
            return '1=1' . $forbidden;
        }
        return 'image_id IN (' . implode(',', $otherFiltersItems) . ')';
    }

    /** @return array<int>|false */
    public function getItemsForFilter(string $filterName): array|false
    {
        $page = &$GLOBALS['page'];
        if (!is_array($page)) {
            $page = [];
        }
        $detailsRaw          = $page['search_details'] ?? [];
        $details             = is_array($detailsRaw) ? $detailsRaw : [];
        $imageIdsForFilter   = is_array($details['image_ids_for_filter'] ?? null) ? $details['image_ids_for_filter'] : [];
        $otherFilters        = array_diff(array_keys($imageIdsForFilter), [$filterName]);

        if (empty($otherFilters)) {
            return false;
        }

        $cacheKey = md5(implode(',', $otherFilters));
        $details  = is_array($page['search_details'] ?? null) ? $page['search_details'] : [];
        $cache    = is_array($details['getItemsForFilter'] ?? null) ? $details['getItemsForFilter'] : [];

        if (!isset($cache[$cacheKey])) {
            $functionStart = get_moment();
            $first         = $imageIdsForFilter[array_shift($otherFilters)] ?? [];
            $otherFiltersItems = is_array($first)
                ? array_values(array_filter($first, fn ($v): bool => is_int($v) || is_string($v)))
                : [];
            foreach ($otherFilters as $otherFilter) {
                $nextRaw           = is_array($imageIdsForFilter[$otherFilter] ?? null) ? $imageIdsForFilter[$otherFilter] : [];
                $next              = array_filter($nextRaw, fn ($v): bool => is_int($v) || is_string($v));
                $otherFiltersItems = array_intersect($otherFiltersItems, $next);
            }
            $otherFiltersItems = array_values(array_unique($otherFiltersItems));
            $debugMsg  = '[getItemsForFilter] cache computed for ' . (count($otherFilters) + 1) . ' other filters';
            $debugMsg .= ' (' . count($otherFiltersItems) . ' items)';
            $debugMsg .= ', time = ' . get_elapsed_time($functionStart, get_moment());
            $this->logger->debug($debugMsg);

            if (empty($otherFiltersItems)) {
                $otherFiltersItems = [-1];
            }

            $cache[$cacheKey]             = $otherFiltersItems;
            $details['getItemsForFilter'] = $cache;
            $page['search_details']       = $details;
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
     * @return non-falsy-string[]
     */
    public function qsearchGetTextTokenSearchSql(QSingleToken $token, array $fields): array
    {
        $page = &$GLOBALS['page'];
        if (!is_array($page)) {
            $page = [];
        }
        $clauses  = [];
        $variants = array_merge([$token->term], $token->variants);
        $fts      = [];
        foreach ($variants as $variant) {
            $useFt = mb_strlen((string) $variant) > 3;
            if ($token->modifier & QST_WILDCARD_BEGIN) {
                $useFt = false;
            }
            if (($token->modifier & (QST_QUOTED | QST_WILDCARD_END)) === (QST_QUOTED | QST_WILDCARD_END)) {
                $useFt = false;
            }
            if ($useFt) {
                $parts = preg_split('/[' . preg_quote('-\'!"#$%&()*+,./:;<=>?@[\]^`{|}~', '/') . ']+/', (string) $variant);
                $max   = ($parts !== false) ? max(array_map(mb_strlen(...), $parts)) : 0;
                if ($max < 4) {
                    $useFt = false;
                }
            }
            if (!$useFt) {
                if (!isset($page['use_regexp_ICU'])) {
                    $page['use_regexp_ICU'] = false;
                    $dbVersion = DbInfo::version();
                    if (!preg_match('/mariadb/i', $dbVersion) and version_compare($dbVersion, '8.0.4', '>')) {
                        $page['use_regexp_ICU'] = true;
                    }
                }
                $pre  = ($token->modifier & QST_WILDCARD_BEGIN) ? '' : ($page['use_regexp_ICU'] ? '\\\\b' : '[[:<:]]');
                $post = ($token->modifier & QST_WILDCARD_END) ? '' : ($page['use_regexp_ICU'] ? '\\\\b' : '[[:>:]]');
                foreach ($fields as $field) {
                    $clauses[] = $field . ' REGEXP \'' . $pre . addslashes(preg_quote((string) $variant)) . $post . '\'';
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
        $queryBase        = 'SELECT id from ' . IMAGES_TABLE . ' i WHERE' . "\n";
        for ($i = 0; $i < count($expr->stokens); $i++) {
            $token      = $expr->stokens[$i];
            $scopeId    = isset($token->scope) ? $token->scope->id : 'photo';
            $tokenScope = $token->scope;
            $clauses    = [];
            $like       = addslashes((string) $token->term);
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
                    if (strlen((string) $token->term)) {
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
                    $clauses = trigger_change('qsearch_get_images_sql_scopes', $clauses, $token, $expr);
                    break;
            }
            if (!empty($clauses)) {
                $query = $queryBase . '(' . implode("\n OR ", $clauses) . ')';
                $qsr->images_iids[$i] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id'));
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
            $query   = 'SELECT * FROM ' . TAGS_TABLE . ' WHERE (' . implode("\n OR ", $clauses) . ')';
            foreach ($this->conn->executeQuery($query)->fetchAllAssociative() as $tag) {
                $tagId               = is_numeric($tag['id']) ? (int) $tag['id'] : 0;
                $tokenTagIds[$i][]   = $tagId;
                $allTags[$tagId]     = $tag;
            }
        }

        for ($i = 0; $i < count($expr->stokens) - 1; $i++) {
            if ((strlen((string) $expr->stokens[$i]->term) <= 3 || strlen((string) $expr->stokens[$i + 1]->term) <= 3)
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
                $qsr->tag_iids[$i] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(get_dbal_connection()->executeQuery('SELECT image_id FROM ' . IMAGE_TAG_TABLE . ' WHERE tag_id IN (' . implode(',', $tagIds) . ') GROUP BY image_id')->fetchAllAssociative(), 'image_id'));
                if ($expr->stoken_modifiers[$i] & QST_NOT) {
                    $notIds = array_merge($notIds, $tagIds);
                } else {
                    if (strlen((string) $token->term) > 2 || count($expr->stokens) == 1 || isset($token->scope) || ($token->modifier & (QST_WILDCARD | QST_QUOTED))) {
                        $positiveIds = array_merge($positiveIds, $tagIds);
                    }
                }
            } elseif (isset($token->scope) && 'tag' == $token->scope->id && strlen($token->term) == 0) {
                if ($token->modifier & QST_WILDCARD) {
                    $qsr->tag_iids[$i] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT image_id FROM ' . IMAGE_TAG_TABLE)->fetchAllAssociative(), 'image_id'));
                } else {
                    $qsr->tag_iids[$i] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . IMAGES_TABLE . ' LEFT JOIN ' . IMAGE_TAG_TABLE . ' ON id=image_id WHERE image_id IS NULL')->fetchAllAssociative(), 'id'));
                }
            }
        }

        $allTags = array_intersect_key($allTags, array_flip(array_diff($positiveIds, $notIds)));
        usort($allTags, tag_alpha_compare(...));
        foreach ($allTags as &$tag) {
            $tagName    = trigger_change('render_tag_name', $tag['name'], $tag);
            $tag['name'] = is_string($tagName) ? $tagName : (is_scalar($tagName) ? (string) $tagName : '');
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
            $query   = 'SELECT * FROM ' . CATEGORIES_TABLE . ' INNER JOIN ' . USER_CACHE_CATEGORIES_TABLE . ' ON id = cat_id AND user_id = ' . $userId . ' WHERE (' . implode("\n OR ", $clauses) . ')';
            foreach ($this->conn->executeQuery($query)->fetchAllAssociative() as $cat) {
                $catId             = is_numeric($cat['id']) ? (int) $cat['id'] : 0;
                $tokenCatIds[$i][] = $catId;
                $allCats[$catId]   = $cat;
            }
        }

        for ($i = 0; $i < count($expr->stokens) - 1; $i++) {
            if ((strlen((string) $expr->stokens[$i]->term) <= 3 || strlen((string) $expr->stokens[$i + 1]->term) <= 3)
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
                    $catIds = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . CATEGORIES_TABLE . ' INNER JOIN ' . USER_CACHE_CATEGORIES_TABLE . ' ON id = cat_id and user_id = ' . $userId . ' WHERE id IN (' . implode(',', get_subcat_ids($catIds)) . ');')->fetchAllAssociative(), 'id'));
                }
                $qsr->cat_iids[$i] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(get_dbal_connection()->executeQuery('SELECT image_id FROM ' . IMAGE_CATEGORY_TABLE . ' WHERE category_id IN (' . implode(',', $catIds) . ') GROUP BY image_id')->fetchAllAssociative(), 'image_id'));
                if ($expr->stoken_modifiers[$i] & QST_NOT) {
                    $notIds = array_merge($notIds, $catIds);
                } else {
                    if (strlen((string) $token->term) > 2 || count($expr->stokens) == 1 || isset($token->scope) || ($token->modifier & (QST_WILDCARD | QST_QUOTED))) {
                        $positiveIds = array_merge($positiveIds, $catIds);
                    }
                }
            } elseif (isset($token->scope) && 'category' == $token->scope->id && strlen($token->term) == 0) {
                if ($token->modifier & QST_WILDCARD) {
                    $qsr->cat_iids[$i] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT image_id FROM ' . IMAGE_CATEGORY_TABLE)->fetchAllAssociative(), 'image_id'));
                } else {
                    $qsr->cat_iids[$i] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . IMAGES_TABLE . ' LEFT JOIN ' . IMAGE_CATEGORY_TABLE . ' ON id=image_id WHERE image_id IS NULL')->fetchAllAssociative(), 'id'));
                }
            }
        }

        $allCats = array_intersect_key($allCats, array_flip(array_diff($positiveIds, $notIds)));
        usort($allCats, tag_alpha_compare(...));
        foreach ($allCats as &$cat) {
            $catName    = trigger_change('render_category_name', $cat['name'], $cat);
            $cat['name'] = is_string($catName) ? $catName : (is_scalar($catName) ? (string) $catName : '');
        }
        $qsr->all_cats = $allCats;
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
            } elseif ($crt instanceof QMultiToken) {
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
        $persistentCache = PersistentCacheRegistry::current();
        $currentUser     = CurrentUser::get();
        $cacheUpdate     = is_scalar($currentUser->rawAttributes['cache_update_time'] ?? null)
            ? (string) $currentUser->rawAttributes['cache_update_time']
            : '';

        $cacheKey = $persistentCache->makeKey([
            strtolower($q),
            Config::orderBy(),
            $currentUser->id, $cacheUpdate,
            isset($options['permissions']) ? (bool) $options['permissions'] : true,
            $options['images_where'] ?? '',
        ]);
        /** @var mixed $res */
        $res      = false;
        $cacheHit = $persistentCache->get($cacheKey, $res);
        if ($cacheHit) {
            return is_array($res) ? $res : null;
        }

        $res      = $this->getQuickSearchResultsNoCache($q, $options);
        $resItems = is_array($res['items'] ?? null) ? $res['items'] : [];
        if (count($resItems)) {
            $persistentCache->set($cacheKey, $res, 300);
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
        $q             = trigger_change('qsearch_pre', $q);

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

        $scopes     = trigger_change('qsearch_get_scopes', $scopes);
        $expression = new QExpression($q, $scopes);

        $langCode = substr(UserService::get()->getDefaultLanguage(), 0, 2);
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
                if (strlen((string) $token->term) > 2
                  && ($token->modifier & (QST_QUOTED | QST_WILDCARD)) == 0
                  && strcspn((string) $token->term, '\'0123456789') == strlen((string) $token->term)) {
                    $token->variants = array_unique(array_diff($inflector->getVariants($token->term), [$token->term]));
                }
            }
        }

        trigger_notify('qsearch_expression_parsed', $expression);

        if (count($expression->stokens) == 0) {
            return $searchResults;
        }
        $qsr = new QResults();
        $this->qsearchGetTags($expression, $qsr);
        $this->qsearchGetCategories($expression, $qsr);
        $this->qsearchGetImages($expression, $qsr);

        trigger_notify('qsearch_before_eval', $expression, $qsr);

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
        $searchResults = trigger_change('qsearch_results', $searchResults, $expression, $qsr);
        $extraItems    = array_map(static fn (mixed $v): int => (int) $v, $searchResults['items']);
        $ids           = array_merge($ids, $extraItems);

        if (empty($ids)) {
            $debug[] = '-->';
            TemplateRegistry::current()->append('footer_elements', implode("\n", $debug));
            return $searchResults;
        }

        $permissions   = !isset($options['permissions']) ? true : $options['permissions'];
        $whereClauses  = [];
        $whereClauses[] = 'i.id IN (' . implode(',', $ids) . ')';
        if (!empty($options['images_where'])) {
            $whereClauses[] = '(' . (is_scalar($options['images_where']) ? (string) $options['images_where'] : '') . ')';
        }
        if ($permissions) {
            $whereClauses[] = PermissionService::get()->getSqlConditionFandF(['forbidden_categories' => 'category_id', 'forbidden_images' => 'i.id'], null, true);
        }

        $query = 'SELECT DISTINCT(id) FROM ' . IMAGES_TABLE . ' i';
        if ($permissions) {
            $query .= ' INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id';
        }
        $query .= ' WHERE ' . implode("\n AND ", $whereClauses) . "\n" . Config::orderBy();
        $ids   = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');

        $debug[] = count($ids) . ' final photo count -->';
        TemplateRegistry::current()->append('footer_elements', implode("\n", $debug));

        $searchResults['items'] = $ids;
        return $searchResults;
    }

    /** @return array<mixed> */
    public function getSearchResults(int|string $searchId, bool $superOrderBy, ?string $imagesWhere = ''): array
    {
        $search = $this->getSearchArray($searchId);
        if (!isset($search['q'])) {
            return $this->getRegularSearchResults($search, $imagesWhere);
        } else {
            $searchQ = is_scalar($search['q']) ? (string) $search['q'] : '';
            return $this->getQuickSearchResults($searchQ, ['super_order_by' => $superOrderBy, 'images_where' => $imagesWhere]) ?? [];
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
        $candidate = 'psk-' . date('Ymd') . '-' . generate_key(10);
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

        single_insert(SEARCH_TABLE, [
            'rules'       => serialize($rules),
            'created_on'  => $dbnow,
            'created_by'  => $createdBy,
            'search_uuid' => $searchUuid,
            'forked_from' => $forkedFrom,
        ]);

        if (!PermissionService::get()->isAGuest() and !PermissionService::get()->isGeneric()) {
            $rulesFields = is_array($rules['fields'] ?? null) ? $rules['fields'] : [];
            PreferencesService::get()->userprefsUpdateParam('gallery_search_filters', array_keys($rulesFields));
        }

        $url = make_index_url(['section' => 'search', 'search' => $searchUuid]);

        return [$searchUuid, $url];
    }
}
