<?php

declare(strict_types=1);

namespace Piwigo\Ws\Method;

use Doctrine\DBAL\ParameterType;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Metadata\MetadataAdminService;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentRepository;
use Piwigo\Comment\CommentService;
use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\Filesystem;
use Piwigo\Core\Lang;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\Paths;
use Piwigo\Core\StringUtil;
use Piwigo\Core\ValidationPattern;
use Piwigo\Csrf\CsrfService;
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Event\Picture\RenderElementName;
use Piwigo\Event\Picture\WsImagesUploadCompleted;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Rate\RateRepository;
use Piwigo\Rate\RateService;
use Piwigo\Search\SearchService;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Tag\TagService;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Ws\Encoder\PwgResponseEncoder;
use Piwigo\Ws\OpenApi\ApiMethod;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsHelper;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class ImagesEndpoints
{
    public function __construct(
        private CategoryAdminService $categoryAdminService,
        private CategoryRepository $categoryRepository,
        private CategoryService $categoryService,
        private CommentRepository $commentRepository,
        private CommentService $commentService,
        private HtmlService $htmlService,
        private ImageAdminService $imageAdminService,
        private ImageRepository $imageRepository,
        private MetadataAdminService $metadataAdminService,
        private PermissionService $permissionService,
        private RateRepository $rateRepository,
        private RateService $rateService,
        private SearchService $searchService,
        private TagAdminService $tagAdminService,
        private TagService $tagService,
        private UploadService $uploadService,
        private UrlService $urlService,
        private UserAdminService $userAdminService,
        private ActivityLogger $activityLogger,
        private CsrfService $csrfService,
        private WsHelper $wsHelper,
        private EphemeralKeyService $ephemeralKeyService,
        private EventDispatcherInterface $dispatcher,
        private Paths $paths,
    ) {
    }

    // ── Internal helpers ─────────────────────────────────────────────────

    public function addImageCategoryRelations(int $imageId, string $categoriesString, bool $replaceMode = false): true|PwgError
    {
        $catIds          = [];
        $rankOnCategory  = [];
        $searchCurrentRanks = false;
        if (empty($categoriesString)) {
            if ($replaceMode) {
                $this->categoryRepository->deleteImageCategoryByImageIds([$imageId]);
                $this->categoryAdminService->updateCategory([]);
            }
            return true;
        }
        $tokens = explode(';', $categoriesString);
        foreach ($tokens as $token) {
            $parts  = explode(',', $token);
            $catId  = $parts[0];
            $rank   = $parts[1] ?? null;
            if (!preg_match('/^\d+$/', $catId)) {
                continue;
            }
            $catIds[] = $catId;
            $rankOnCategory[$catId] = ($rank === null || $rank === '') ? 'auto' : $rank;
            if ($rankOnCategory[$catId] === 'auto') {
                $searchCurrentRanks = true;
            }
        }
        $catIds = array_unique($catIds);
        if (count($catIds) === 0) {
            if ($replaceMode) {
                $this->categoryRepository->deleteImageCategoryByImageIds([$imageId]);
                $this->categoryAdminService->updateCategory([]);
            }
            return true;
        }
        $catIdsInt   = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $catIds);
        $dbCatIds    = $this->categoryRepository->findExistingIdsAmong($catIdsInt);
        $unknownCatIds = array_diff($catIdsInt, $dbCatIds);
        if (count($unknownCatIds) !== 0) {
            return new PwgError(500, '[addImageCategoryRelations] the following categories are unknown: ' . implode(', ', $unknownCatIds));
        }
        $existingCatIds = $this->categoryRepository->findCategoryIdsByImageId($imageId);
        if ($replaceMode) {
            $toRemoveCatIds = array_values(array_diff($existingCatIds, $catIdsInt));
            if (count($toRemoveCatIds) > 0) {
                $this->categoryRepository->removeImageFromCategories($imageId, $toRemoveCatIds);
                $this->categoryAdminService->updateCategory($toRemoveCatIds);
            }
        }
        $newCatIds = array_values(array_diff($catIdsInt, $existingCatIds));
        if (count($newCatIds) === 0) {
            return true;
        }
        if ($searchCurrentRanks) {
            $currentRankOf = $this->categoryRepository->findMaxImageRankPerCategoryIn($newCatIds);
            foreach ($newCatIds as $catId) {
                if (!isset($currentRankOf[$catId])) {
                    $currentRankOf[$catId] = 0;
                }
                if ($rankOnCategory[$catId] === 'auto') {
                    $rankOnCategory[$catId] = $currentRankOf[$catId] + 1;
                }
            }
        }
        $inserts = [];
        foreach ($newCatIds as $catId) {
            $inserts[] = [
                'image_id'    => $imageId,
                'category_id' => $catId,
                'rank'        => is_numeric($rankOnCategory[$catId]) ? (int) $rankOnCategory[$catId] : 0,
            ];
        }
        $this->categoryRepository->insertImageCategoryLinks($inserts);
        $this->categoryAdminService->updateCategory($newCatIds);
        return true;
    }

    public function mergeChunks(string $outputFilepath, string $originalSum, string $type): mixed
    {
        $logger = LoggerRegistry::current();
        $logger->debug('[mergeChunks] input $outputFilepath : ' . $outputFilepath);
        if (is_file($outputFilepath)) {
            unlink($outputFilepath);
            if (is_file($outputFilepath)) {
                return new PwgError(500, '[mergeChunks] error while trying to remove existing ' . $outputFilepath);
            }
        }
        $uploadDir = Config::uploadDir() . '/buffer';
        $pattern   = '/' . $originalSum . '-' . $type . '/';
        $chunks    = [];
        if ($handle = opendir($uploadDir)) {
            while (false !== ($file = readdir($handle))) {
                if (preg_match($pattern, $file)) {
                    $chunks[] = $uploadDir . '/' . $file;
                }
            }
            closedir($handle);
        }
        sort($chunks);
        foreach ($chunks as $chunk) {
            $string = file_get_contents($chunk);
            if ($string === false || file_put_contents($outputFilepath, $string, FILE_APPEND) === false) {
                return new PwgError(500, '[mergeChunks] error while writting chunks for ' . $outputFilepath);
            }
            unlink($chunk);
        }
        return null;
    }

    public function removeChunks(string $originalSum, string $type): void
    {
        $uploadDir = Config::uploadDir() . '/buffer';
        $pattern   = '/' . $originalSum . '-' . $type . '/';
        $chunks    = [];
        if ($handle = opendir($uploadDir)) {
            while (false !== ($file = readdir($handle))) {
                if (preg_match($pattern, $file)) {
                    $chunks[] = $uploadDir . '/' . $file;
                }
            }
            closedir($handle);
        }
        foreach ($chunks as $chunk) {
            unlink($chunk);
        }
    }

    // ── API methods ──────────────────────────────────────────────────────

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Adds a comment to an image.', tags: ['images'])]
    public function addComment(array $params, PwgServer $service): PwgError|array
    {
        if (!Config::activateComments()) {
            return new PwgError(403, 'Comments are disabled');
        }
        $pImageId = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        // `commentable` is TINYINT(1) post-E2; the legacy 'true' coerces to 0,
        // which would return NOT-commentable rows — fixed to 1.
        [$permSql, $permParams, $permTypes] = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'id', 'visible_categories' => 'id', 'visible_images' => 'image_id'], ' AND');
        if (!$this->categoryRepository->isImageInVisibleCommentableCategory($pImageId, $permSql, $permParams, $permTypes)) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid image_id');
        }
        $comm = ['author' => trim(is_string($params['author'] ?? null) ? $params['author'] : ''), 'content' => trim(is_string($params['content'] ?? null) ? $params['content'] : ''), 'image_id' => $pImageId];
        $infos         = [];
        $commentAction = $this->commentService->insertUserComment($comm, is_string($params['key'] ?? null) ? $params['key'] : '', $infos);
        switch ($commentAction) {
            case 'reject':
                $infos[] = Lang::t('Your comment has NOT been registered because it did not pass the validation rules');
                return new PwgError(403, implode('; ', $infos));
            case 'validate':
            case 'moderate':
                return ['comment' => new PwgNamedStruct(['id' => $comm['id'], 'validation' => $commentAction === 'validate'])];
            default:
                return new PwgError(500, 'Unknown comment action ' . $commentAction);
        }
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Returns information about an image.', tags: ['images'])]
    public function getInfo(array $params, PwgServer $service): PwgError|array
    {
        $pImageId = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        [$permSql, $permParams, $permTypes] = $this->permissionService->getSqlConditionFandF(['visible_images' => 'id'], ' AND');
        $imageRow = $this->imageRepository->findByIdWithPermissions($pImageId, $permSql, $permParams, $permTypes);
        if ($imageRow === null) {
            return new PwgError(404, 'image_id not found');
        }
        /** @var array<string, mixed> $imageRow */
        $imageRow      = array_merge($imageRow, $this->wsHelper->getUrls($imageRow));
        $imageRowId    = is_numeric($imageRow['id']) ? (int) $imageRow['id'] : 0;
        $imageRowFile  = is_string($imageRow['file'] ?? null) ? $imageRow['file'] : '';
        $imageRow['name_raw']    = $imageRow['name'];
        $rawImageName            = $imageRow['name'] ?? null;
        $renderEvent             = new RenderElementName(is_string($rawImageName) ? $rawImageName : '', $imageRow);
        $this->dispatcher->dispatch($renderEvent);
        $imageRow['name']        = strip_tags($renderEvent->elementName);
        $imageRow['comment_raw'] = $imageRow['comment'];
        $rowDescEvent            = new RenderElementDescription(is_string($imageRow['comment']) ? $imageRow['comment'] : '', __FUNCTION__);
        $this->dispatcher->dispatch($rowDescEvent);
        $imageRow['comment']     = $rowDescEvent->elementDescription;
        $isCommentable    = false;
        $relatedCategories = [];
        [$relPermSql, $relPermParams, $relPermTypes] = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'category_id'], ' AND');
        foreach ($this->categoryRepository->findRelatedCategoriesForImage($imageRowId, $relPermSql, $relPermParams, $relPermTypes) as $row) {
            if (BoolUtil::fromMixed($row['commentable'])) {
                $isCommentable = true;
            }
            unset($row['commentable']);
            $row['url']      = $this->urlService->makeIndexUrl(['category' => $row]);
            $row['page_url'] = $this->urlService->makePictureUrl(['image_id' => $imageRowId, 'image_file' => $imageRowFile, 'category' => $row]);
            $row['id']       = is_numeric($row['id']) ? (int) $row['id'] : 0;
            $rawCatName      = $row['name'] ?? null;
            $catRenderEvent  = new RenderCategoryName(is_string($rawCatName) ? $rawCatName : '', __FUNCTION__);
            $this->dispatcher->dispatch($catRenderEvent);
            $row['name']     = strip_tags($catRenderEvent->categoryName);
            $relatedCategories[] = $row;
        }
        usort($relatedCategories, $this->categoryService->globalRankCompare(...));
        if (empty($relatedCategories) && !$this->permissionService->isAdmin()) {
            return new PwgError(401, 'Access denied');
        }
        /** @var list<array<string, mixed>> $relatedTags */
        $relatedTags = $this->tagService->getCommonTags([$imageRowId], -1);
        foreach ($relatedTags as $i => $tag) {
            $tag['url']      = $this->urlService->makeIndexUrl(['tags' => [$tag]]);
            $tag['page_url'] = $this->urlService->makePictureUrl(['image_id' => $imageRowId, 'image_file' => $imageRowFile, 'tags' => [$tag]]);
            unset($tag['counter']);
            $tag['id']       = is_numeric($tag['id']) ? (int) $tag['id'] : 0;
            $relatedTags[$i] = $tag;
        }
        $rating = ['score' => $imageRow['rating_score'], 'count' => 0, 'average' => null];
        if (isset($rating['score'])) {
            [$rating['count'], $rating['average']] = $this->rateRepository->findCountAndAvgByElementId($imageRowId);
            $rating['score'] = is_numeric($rating['score']) ? (float) $rating['score'] : 0.0;
        }
        $relatedComments = [];
        $whereComments   = 'image_id = ?';
        $commentParams   = [$imageRowId];
        $commentTypes    = [ParameterType::INTEGER];
        if (!$this->permissionService->isAdmin()) {
            // post-E2, validated is TINYINT(1); `validated = 1` for approved comments.
            $whereComments .= ' AND validated = 1';
        }
        $nbComments       = $this->commentRepository->countByWhereFragment($whereComments, $commentParams, $commentTypes);
        $pCommentsPerPage = is_numeric($params['comments_per_page']) ? (int) $params['comments_per_page'] : 0;
        $pCommentsPage    = is_numeric($params['comments_page']) ? (int) $params['comments_page'] : 0;
        if ($nbComments > 0 && $pCommentsPerPage > 0) {
            foreach ($this->commentRepository->findByWhereFragmentOrderedByDate($whereComments, $pCommentsPerPage, $pCommentsPerPage * $pCommentsPage, $commentParams, $commentTypes) as $row) {
                $row['id']         = is_numeric($row['id']) ? (int) $row['id'] : 0;
                $relatedComments[] = $row;
            }
        }
        $commentPostData = null;
        if (Config::activateComments() && $isCommentable && (!$this->permissionService->isAGuest() || Config::commentsForall())) {
            $commentPostData['author'] = stripslashes(CurrentUser::get()->username);
            $commentPostData['key']    = $this->ephemeralKeyService->generate(2, (string) $pImageId);
        }
        $ret = $imageRow;
        foreach (['id', 'width', 'height', 'hit', 'filesize'] as $k) {
            if (isset($ret[$k])) {
                $ret[$k] = is_numeric($ret[$k]) ? (int) $ret[$k] : 0;
            }
        }
        unset($ret['path'], $ret['storage_category_id']);
        $ret['rates']    = [PwgResponseEncoder::ATTRIBUTES_KEY => $rating];
        $ret['categories'] = new PwgNamedArray($relatedCategories, 'category', ['id', 'url', 'page_url']);
        $ret['tags']       = new PwgNamedArray($relatedTags, 'tag', $this->wsHelper->getTagXmlAttributes());
        if (isset($commentPostData)) {
            $ret['comment_post'] = [PwgResponseEncoder::ATTRIBUTES_KEY => $commentPostData];
        }
        $ret['comments_paging'] = new PwgNamedStruct(['page' => $params['comments_page'], 'per_page' => $params['comments_per_page'], 'count' => count($relatedComments), 'total_count' => $nbComments]);
        $ret['comments']        = new PwgNamedArray($relatedComments, 'comment', ['id', 'date']);
        if ($service->getResponseFormat() !== 'rest') {
            return $ret;
        }
        return ['image' => new PwgNamedStruct($ret, null, ['name', 'comment'])];
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Rates an image.', tags: ['images'])]
    public function rate(array $params, PwgServer $service): mixed
    {
        $pImageId = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $pRate    = is_numeric($params['rate']) ? (int) $params['rate'] : 0;
        [$ratePermSql, $ratePermParams, $ratePermTypes] = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'category_id', 'forbidden_images' => 'id'], '    AND');
        if (!$this->categoryRepository->isImageInVisibleCategory($pImageId, $ratePermSql, $ratePermParams, $ratePermTypes)) {
            return new PwgError(404, 'Invalid image_id or access denied');
        }
        $res = $this->rateService->ratePicture($pImageId, $pRate);
        if ($res === false) {
            return new PwgError(403, 'Forbidden or rate not in ' . implode(',', Config::rateItems()));
        }
        return $res;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    #[ApiMethod(summary: 'Returns elements for the corresponding query search.', tags: ['images'])]
    public function search(array $params, PwgServer $service): array
    {
        $pQuery    = is_string($params['query'] ?? null) ? $params['query'] : '';
        $pPage     = is_numeric($params['page']) ? (int) $params['page'] : 0;
        $pPerPage  = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
        $images    = [];
        /** @var array<string> $whereClauses */
        $whereClauses = $this->wsHelper->imageSqlFilter($params, 'i.');
        $orderBy      = $this->wsHelper->imageSqlOrder($params, 'i.');
        $superOrderBy = false;
        if (!empty($orderBy)) {
            Config::override('order_by', 'ORDER BY ' . $orderBy);
            $superOrderBy = true;
        }
        $searchResult = $this->searchService->getQuickSearchResults($pQuery, ['super_order_by' => $superOrderBy, 'images_where' => implode(' AND ', $whereClauses)]);
        $searchResultArr = $searchResult ?? [];
        $searchItems  = is_array($searchResultArr['items'] ?? null) ? $searchResultArr['items'] : [];
        $imageIds     = array_slice($searchItems, $pPage * $pPerPage, $pPerPage);
        if (count($imageIds)) {
            $imageIdsInt  = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $imageIds);
            $imageIdsFlip = array_flip($imageIdsInt);
            $favoriteIds  = $this->urlService->getUserFavorites();
            foreach ($this->imageRepository->findByIds($imageIdsInt) as $img) {
                $imgIdInt = $img->id->value;
                $image    = [
                    'is_favorite'    => isset($favoriteIds[$imgIdInt]),
                    'id'             => $imgIdInt,
                    'width'          => $img->width ?? 0,
                    'height'         => $img->height ?? 0,
                    'hit'            => $img->hit,
                    'file'           => $img->file->value,
                    'name'           => $img->name,
                    'comment'        => $img->comment,
                    'date_creation'  => $img->dateCreation?->value,
                    'date_available' => $img->dateAvailable?->value,
                ];
                $renderEvent2     = new RenderElementName($img->name ?? '', $image);
                $this->dispatcher->dispatch($renderEvent2);
                $image['name']    = strip_tags($renderEvent2->elementName);
                $imgDescEvent     = new RenderElementDescription($img->comment ?? '', __FUNCTION__);
                $this->dispatcher->dispatch($imgDescEvent);
                $image['comment'] = $imgDescEvent->elementDescription;
                $image = array_merge($image, $this->wsHelper->getUrls([
                    'id'                 => $imgIdInt,
                    'file'               => $img->file->value,
                    'path'               => $img->path->value,
                    'representative_ext' => $img->representativeExt,
                    'width'              => $img->width,
                    'height'             => $img->height,
                    'rotation'           => $img->rotation ?? 0,
                ]));
                if (isset($imageIdsFlip[$imgIdInt])) {
                    $images[$imageIdsFlip[$imgIdInt]] = $image;
                }
            }
            ksort($images, SORT_NUMERIC);
            $images = array_values($images);
        }
        return ['paging' => new PwgNamedStruct(['page' => $pPage, 'per_page' => $pPerPage, 'count' => count($images), 'total_count' => count($searchItems)]), 'images' => new PwgNamedArray($images, 'image', $this->wsHelper->getImageXmlAttributes())];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Create a filtered search persisted to the search store.', tags: ['images'])]
    public function filteredSearchCreate(array $params, PwgServer $service): PwgError|array
    {
        $searchInfo = null;
        if (isset($params['search_id'])) {
            $pSearchId = is_string($params['search_id']) ? $params['search_id'] : '';
            $searchPattern = $this->searchService->getSearchIdPattern($pSearchId);
            if ($searchPattern === null || $searchPattern === '') {
                return new PwgError(WsError::InvalidParam->value, 'Invalid search_id input parameter.');
            }
            $searchInfo = $this->searchService->getSearchInfo($pSearchId);
            if ($searchInfo === null || count($searchInfo) === 0) {
                return new PwgError(WsError::InvalidParam->value, 'This search does not exist.');
            }
        }
        $search = ['mode' => 'AND', 'fields' => []];
        if (isset($params['allwords'])) {
            $search['fields']['allwords'] = [];
            if (!isset($params['allwords_mode'])) {
                $params['allwords_mode'] = 'AND';
            }
            $pAllwordsMode = is_string($params['allwords_mode']) ? $params['allwords_mode'] : '';
            if (!preg_match('/^(OR|AND)$/', $pAllwordsMode)) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid parameter allwords_mode');
            }
            $search['fields']['allwords']['mode'] = $pAllwordsMode;
            $allwordsFieldsAvailable = ['name', 'comment', 'file', 'author', 'tags', 'cat-title', 'cat-desc'];
            if (!isset($params['allwords_fields'])) {
                $params['allwords_fields'] = $allwordsFieldsAvailable;
            }
            $pAllwordsFields = is_array($params['allwords_fields']) ? $params['allwords_fields'] : [];
            foreach ($pAllwordsFields as $field) {
                if (!in_array($field, $allwordsFieldsAvailable)) {
                    return new PwgError(WsError::InvalidParam->value, 'Invalid parameter allwords_fields');
                }
            }
            $search['fields']['allwords']['fields'] = $pAllwordsFields;
            $search['fields']['allwords']['words']  = $this->searchService->splitAllwords(is_string($params['allwords']) ? $params['allwords'] : '');
        }
        if (isset($params['tags'])) {
            $pTags = is_array($params['tags']) ? $params['tags'] : [];
            foreach ($pTags as $tagId) {
                if (!preg_match('/^\d+$/', is_scalar($tagId) ? (string) $tagId : '')) {
                    return new PwgError(WsError::InvalidParam->value, 'Invalid parameter tags');
                }
            }
            if (!isset($params['tags_mode'])) {
                $params['tags_mode'] = 'AND';
            }
            $pTagsMode = is_string($params['tags_mode']) ? $params['tags_mode'] : '';
            if (!preg_match('/^(OR|AND)$/', $pTagsMode)) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid parameter tags_mode');
            }
            $search['fields']['tags'] = ['words' => $pTags, 'mode' => $pTagsMode];
        }
        if (isset($params['categories'])) {
            $pCategories = is_array($params['categories']) ? $params['categories'] : [];
            foreach ($pCategories as $catId) {
                if (!preg_match('/^\d+$/', is_scalar($catId) ? (string) $catId : '')) {
                    return new PwgError(WsError::InvalidParam->value, 'Invalid parameter categories');
                }
            }
            $search['fields']['cat'] = ['words' => $pCategories, 'sub_inc' => $params['categories_withsubs'] ?? false];
        }
        if (isset($params['authors'])) {
            $authors   = [];
            $pAuthors  = is_array($params['authors']) ? $params['authors'] : [];
            foreach ($pAuthors as $author) {
                $authors[] = strip_tags(is_scalar($author) ? (string) $author : '');
            }
            $search['fields']['author'] = ['words' => $authors, 'mode' => 'OR'];
        }
        if (isset($params['filetypes'])) {
            $pFiletypes = is_array($params['filetypes']) ? $params['filetypes'] : [];
            foreach ($pFiletypes as $ext) {
                if (!preg_match('/^[a-z0-9]+$/i', is_scalar($ext) ? (string) $ext : '')) {
                    return new PwgError(WsError::InvalidParam->value, 'Invalid parameter filetypes');
                }
            }
            $search['fields']['filetypes'] = $pFiletypes;
        }
        if (isset($params['added_by'])) {
            $pAddedBy = is_array($params['added_by']) ? $params['added_by'] : [];
            foreach ($pAddedBy as $userId) {
                if (!preg_match('/^\d+$/', is_scalar($userId) ? (string) $userId : '')) {
                    return new PwgError(WsError::InvalidParam->value, 'Invalid parameter added_by');
                }
            }
            $search['fields']['added_by'] = $pAddedBy;
        }
        foreach (['date_posted_preset', 'date_created_preset'] as $presetParam) {
            if (isset($params[$presetParam])) {
                $pPreset    = is_scalar($params[$presetParam]) ? (string) $params[$presetParam] : '';
                $validPres  = $presetParam === 'date_posted_preset' ? '/^(24h|7d|30d|3m|6m|custom|)$/' : '/^(7d|30d|3m|6m|12m|custom|)$/';
                if (!preg_match($validPres, $pPreset)) {
                    return new PwgError(WsError::InvalidParam->value, 'Invalid parameter ' . $presetParam);
                }
                $fieldKey = $presetParam === 'date_posted_preset' ? 'date_posted' : 'date_created';
                $search['fields'][$fieldKey]['preset'] = $pPreset;
            }
        }
        foreach (['date_posted_custom', 'date_created_custom'] as $customParam) {
            if (isset($params[$customParam])) {
                $fieldKey = $customParam === 'date_posted_custom' ? 'date_posted' : 'date_created';
                $pCustom  = is_array($params[$customParam]) ? $params[$customParam] : [];
                foreach ($pCustom as $date) {
                    $dateStr        = is_scalar($date) ? (string) $date : '';
                    $correctFormat  = false;
                    $ymd            = substr($dateStr, 0, 1);
                    if ($ymd === 'y' && preg_match('/^y(\d{4})$/', $dateStr)) {
                        $correctFormat = true;
                    } elseif ($ymd === 'm' && preg_match('/^m(\d{4}-\d{2})$/', $dateStr, $m)) {
                        [$year, $month] = explode('-', $m[1]);
                        if ($month >= 1 && $month <= 12) {
                            $correctFormat = true;
                        }
                    } elseif ($ymd === 'd' && preg_match('/^d(\d{4}-\d{2}-\d{2})$/', $dateStr, $m)) {
                        [$year, $month, $day] = explode('-', $m[1]);
                        if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= cal_days_in_month(CAL_GREGORIAN, (int) $month, (int) $year)) {
                            $correctFormat = true;
                        }
                    }
                    if (!$correctFormat) {
                        return new PwgError(WsError::InvalidParam->value, $customParam . ', invalid option ' . $dateStr);
                    }
                    $search['fields'][$fieldKey]['custom'][] = $dateStr;
                }
            }
        }
        foreach (['ratios', 'ratings', 'filesize_min', 'filesize_max', 'width_min', 'width_max', 'height_min', 'height_max', 'expert'] as $field) {
            $fieldVal = $params[$field] ?? null;
            if ($fieldVal !== null) {
                if ($field === 'ratios') {
                    $pRatios = is_array($fieldVal) ? $fieldVal : [];
                    foreach ($pRatios as $ext) {
                        if (!preg_match('/^[a-z0-9]+$/i', is_scalar($ext) ? (string) $ext : '')) {
                            return new PwgError(WsError::InvalidParam->value, 'Invalid parameter ratios');
                        }
                    }
                    $search['fields']['ratios'] = $pRatios;
                } elseif ($field === 'expert') {
                    $search['fields']['expert'] = ['string' => $fieldVal];
                } elseif ($field === 'ratings' && Config::rateEnabled()) {
                    $search['fields']['ratings'] = $fieldVal;
                } else {
                    $search['fields'][$field] = $fieldVal;
                }
            }
        }
        $forkedFrom = isset($searchInfo['id']) && is_scalar($searchInfo['id']) ? (string) $searchInfo['id'] : null;
        [$searchUuid, $searchUrl] = $this->searchService->saveSearch($search, $forkedFrom);
        return ['search_id' => $searchUuid, 'search_url' => $searchUrl];
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Sets the privacy levels for the images.', tags: ['images'])]
    public function setPrivacyLevel(array $params, PwgServer $service): mixed
    {
        if (!in_array($params['level'], Config::availablePermissionLevels())) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid level');
        }
        $pLevel    = is_numeric($params['level']) ? (int) $params['level'] : 0;
        $pImageIds = is_array($params['image_id']) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['image_id']) : [];
        $affected  = $this->imageRepository->setLevelForIds($pLevel, $pImageIds);
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Photo, $pImageIds, 'edit'));
        if ($affected) {
            $this->userAdminService->invalidateUserCache();
        }
        return $affected;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Sets the rank of a photo for a given album. When image_id is a list, the list order matters and rank is ignored.', tags: ['images'])]
    public function setRank(array $params, PwgServer $service): array|PwgError
    {
        $pImageIdArr   = is_array($params['image_id']) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['image_id']) : [];
        $pCategoryId   = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        if (count($pImageIdArr) > 1) {
            $this->categoryAdminService->saveImagesOrder($pCategoryId, $pImageIdArr);
            $imageIds = $this->imageRepository->findIdsByCategoryIdOrderedByRank($pCategoryId);
            return ['image_id' => $imageIds, 'category_id' => $pCategoryId];
        }
        $pImageId = $pImageIdArr[0] ?? 0;
        if (empty($params['rank'])) {
            return new PwgError(WsError::MissingParam->value, 'rank is missing');
        }
        $catRepo = $this->categoryRepository;
        if (!$this->imageRepository->existsById($pImageId)) {
            return new PwgError(404, 'image_id not found');
        }
        if (!$catRepo->hasImageInCategory($pImageId, $pCategoryId)) {
            return new PwgError(404, 'This image is not associated to this category');
        }
        $pRank    = is_numeric($params['rank']) ? (int) $params['rank'] : 1;
        $maxRank  = $catRepo->findMaxRankInCategory($pCategoryId);
        if ($maxRank !== null) {
            if ($pRank > $maxRank) {
                $pRank = $maxRank + 1;
            }
        } else {
            $pRank = 1;
        }
        $catRepo->incrementRanksFrom($pCategoryId, $pRank);
        $catRepo->setImageRank($pImageId, $pCategoryId, $pRank);
        return ['image_id' => $pImageId, 'category_id' => $pCategoryId, 'rank' => $pRank];
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Add a chunk of a file.', tags: ['images'])]
    public function addChunk(array $params, PwgServer $service): mixed
    {
        $logger    = LoggerRegistry::current();
        $uploadDir = Config::uploadDir() . '/buffer';
        if (!Filesystem::mkgetdir($uploadDir, Filesystem::FLAG_DEFAULT & ~Filesystem::FLAG_DIE_ON_ERROR)) {
            return new PwgError(500, 'error during buffer directory creation');
        }
        $pOriginalSum = is_string($params['original_sum'] ?? null) ? $params['original_sum'] : '';
        $pPosition    = is_numeric($params['position']) ? (int) $params['position'] : 0;
        $pData        = is_string($params['data'] ?? null) ? $params['data'] : '';
        $filename     = sprintf('%s-file-%05u.block', $pOriginalSum, $pPosition);
        $logger->debug('[addChunk] data length : ' . strlen($pData));
        $bytesWritten = file_put_contents($uploadDir . '/' . $filename, base64_decode($pData));
        if ($bytesWritten === false) {
            return new PwgError(500, 'an error has occured while writting chunk ' . $pPosition);
        }
        return null;
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Add or update a file for an existing photo. pwg.images.addChunk must have been called before.', tags: ['images'])]
    public function addFile(array $params, PwgServer $service): mixed
    {
        $logger      = LoggerRegistry::current();
        $logger->debug(__FUNCTION__, $params);
        $pImageId    = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $image       = $this->imageRepository->findById($pImageId);
        if ($image === null) {
            return new PwgError(404, 'image_id not found');
        }
        $imageMd5sum = $image->md5sum !== null ? $image->md5sum->value : '';
        $imageFile   = $image->file->value;
        $filePath    = Config::uploadDir() . '/buffer/' . $imageMd5sum . '-original';
        $this->mergeChunks($filePath, $imageMd5sum, 'file');
        chmod($filePath, Config::chmodValue() & 0o666);
        $infos    = $this->uploadService->pwgImageInfos($filePath);
        $doUpdate = ($infos['width'] > ($image->width ?? 0))
                 || ($infos['height'] > ($image->height ?? 0))
                 || ($infos['filesize'] > ($image->filesize ?? 0));
        if (!$doUpdate) {
            unlink($filePath);
            return true;
        }
        $this->uploadService->addUploadedFile($filePath, $imageFile, null, null, $pImageId, $imageMd5sum);
        return null;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Add an image. pwg.images.addChunk must have been called before.', tags: ['images'])]
    public function add(array $params, PwgServer $service): PwgError|array
    {
        $logger = LoggerRegistry::current();
        $pImageId        = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $pOriginalSum    = is_string($params['original_sum'] ?? null) ? $params['original_sum'] : '';
        $pOriginalFilename = is_scalar($params['original_filename']) ? (string) $params['original_filename'] : null;
        $pLevel          = isset($params['level']) && is_numeric($params['level']) ? (int) $params['level'] : null;
        if ($pImageId > 0 && !$this->imageRepository->existsById($pImageId)) {
            return new PwgError(404, 'image_id not found');
        }
        if ($params['check_uniqueness']) {
            $counter = 0;
            if (Config::uniquenessMode() === 'md5sum') {
                $counter = $this->imageRepository->countByWhereFragment('md5sum = ?', [$pOriginalSum], [ParameterType::STRING]);
            } elseif (Config::uniquenessMode() === 'filename') {
                $counter = $this->imageRepository->countByWhereFragment('file = ?', [is_string($params['original_filename'] ?? null) ? $params['original_filename'] : ''], [ParameterType::STRING]);
            }
            if ($counter !== 0) {
                return new PwgError(500, 'file already exists');
            }
        }
        $filePath = Config::uploadDir() . '/buffer/' . $pOriginalSum . '-original';
        $this->mergeChunks($filePath, $pOriginalSum, 'file');
        chmod($filePath, Config::chmodValue() & 0o666);
        $imageId = $this->uploadService->addUploadedFile($filePath, $pOriginalFilename, null, $pLevel, $pImageId > 0 ? $pImageId : null, $pOriginalSum);
        $update  = [];
        foreach (['name', 'author', 'comment', 'date_creation'] as $key) {
            if (isset($params[$key])) {
                $update[$key] = $params[$key];
            }
        }
        if (count($update) > 0) {
            $this->imageRepository->updateById($imageId, $update);
        }
        $urlParams = ['image_id' => $imageId];
        if (isset($params['categories'])) {
            $pCategoriesStr = is_string($params['categories']) ? $params['categories'] : '';
            $this->addImageCategoryRelations($imageId, $pCategoriesStr);
            if (preg_match('/^\d+/', $pCategoriesStr, $matches)) {
                $category              = $this->categoryRepository->findCategoryById((int) $matches[0]);
                $urlParams['section']  = 'categories';
                $urlParams['category'] = $category;
            }
        }
        if (isset($params['tag_ids']) && $params['tag_ids'] !== '') {
            $this->tagAdminService->setTags(explode(',', is_string($params['tag_ids']) ? $params['tag_ids'] : ''), $imageId);
        }
        $this->userAdminService->invalidateUserCache();
        return ['image_id' => $imageId, 'url' => $this->urlService->makePictureUrl($urlParams)];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Add an image. Use $_FILES[image] for upload, form-data encoding. May update an existing image_id.', tags: ['images'])]
    public function addSimple(array $params, PwgServer $service): PwgError|array
    {
        $logger = LoggerRegistry::current();
        if (!isset($_FILES['image'])) {
            return new PwgError(405, 'The image (file) is missing');
        }
        /** @var array<string, mixed> $filesImage */
        $filesImage      = $_FILES['image'];
        $filesImageError = is_int($filesImage['error'] ?? null) ? $filesImage['error'] : 0;
        if ($filesImageError !== 0) {
            $message = match ($filesImageError) {
                UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive.',
                UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
                default               => "Error number {$filesImageError} occurred while uploading.",
            };
            $logger->error(__FUNCTION__ . ' ' . $message);
            return new PwgError(500, $message);
        }
        $pImageIdAs  = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $pCategoryAs = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['category']) ? $params['category'] : []);
        if ($pImageIdAs > 0 && !$this->imageRepository->existsById($pImageIdAs)) {
            return new PwgError(404, 'image_id not found');
        }
        $filesTmpRaw  = $filesImage['tmp_name'] ?? null;
        $filesTmp     = is_string($filesTmpRaw) ? $filesTmpRaw : '';
        $filesName    = is_string($filesImage['name'] ?? null) ? $filesImage['name'] : null;
        $imageId   = $this->uploadService->addUploadedFile($filesTmp, $filesName, $pCategoryAs, 8, $pImageIdAs > 0 ? $pImageIdAs : null);
        $update    = [];
        foreach (['name', 'author', 'comment', 'level', 'date_creation'] as $key) {
            if (isset($params[$key])) {
                $update[$key] = $params[$key];
            }
        }
        if (count($update) > 0) {
            $this->imageRepository->updateById($imageId, $update);
        }
        if (isset($params['tags']) && !empty($params['tags'])) {
            $tagIds = [];
            if (is_array($params['tags'])) {
                foreach ($params['tags'] as $tagName) {
                    $tagIds[] = $this->tagAdminService->tagIdFromTagName(is_scalar($tagName) ? (string) $tagName : '');
                }
            } else {
                $tagNamesSplit = preg_split('~(?<!\\\),~', is_string($params['tags']) ? $params['tags'] : '');
                $tagNames = $tagNamesSplit !== false ? $tagNamesSplit : [];
                foreach ($tagNames as $tagName) {
                    $tagIds[] = $this->tagAdminService->tagIdFromTagName(preg_replace('#\\\\*,#', ',', $tagName) ?? '');
                }
            }
            $this->tagAdminService->addTags($tagIds, [$imageId]);
        }
        $urlParams = ['image_id' => $imageId];
        if (!empty($pCategoryAs)) {
            $firstCatId  = $pCategoryAs[0] ?? 0;
            $category    = $this->categoryRepository->findCategoryById($firstCatId);
            $urlParams['section']  = 'categories';
            $urlParams['category'] = $category;
        }
        $this->metadataAdminService->syncMetadata([$imageId]);
        return ['image_id' => $imageId, 'url' => $this->urlService->makePictureUrl($urlParams)];
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Add an image. Use $_FILES[image] for upload, form-data encoding.', tags: ['images'])]
    public function upload(array $params, PwgServer $service): mixed
    {
        $formatExt = null;
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (isset($params['format_of'])) {
            if (!Config::isFormatsEnabled()) {
                return new PwgError(401, 'formats are disabled');
            }
            $pNameRaw = $params['name'] ?? null;
            $pName = is_string($pNameRaw) ? $pNameRaw : '';
            if (preg_match('/\.(' . implode('|', Config::formatExtensions()) . ')$/', $pName, $matches)) {
                $formatExt = $matches[1];
            }
            if ($formatExt === null || $formatExt === '') {
                return new PwgError(401, 'unexpected format extension of file "' . $pName . '"');
            }
        }
        $uploadDir = Config::uploadDir() . '/buffer';
        if (!Filesystem::mkgetdir($uploadDir, Filesystem::FLAG_DEFAULT & ~Filesystem::FLAG_DIE_ON_ERROR)) {
            return new PwgError(500, 'error during buffer directory creation');
        }
        if (isset($_REQUEST['name'])) {
            $fileName = is_string($_REQUEST['name']) ? $_REQUEST['name'] : uniqid('file_');
        } elseif (!empty($_FILES)) {
            /** @var array<string, mixed> $filesFile */
            $filesFile     = $_FILES['file'] ?? [];
            $filesFileName = $filesFile['name'] ?? null;
            $fileName      = is_string($filesFileName) ? $filesFileName : uniqid('file_');
        } else {
            $fileName = uniqid('file_');
        }
        $fileName = md5($fileName);
        $filePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
        $chunk    = isset($_REQUEST['chunk']) ? (is_numeric($_REQUEST['chunk']) ? (int) $_REQUEST['chunk'] : 0) : 0;
        $chunks   = isset($_REQUEST['chunks']) ? (is_numeric($_REQUEST['chunks']) ? (int) $_REQUEST['chunks'] : 0) : 0;
        if (!$out = Filesystem::tryFopen("{$filePath}.part", $chunks ? 'ab' : 'wb')) {
            return new PwgError(102, 'Failed to open output stream.');
        }
        if (!empty($_FILES)) {
            /** @var array<string, mixed> $filesFile */
            $filesFile        = $_FILES['file'] ?? [];
            $filesFileErrRaw  = $filesFile['error'] ?? null;
            $filesFileError   = is_int($filesFileErrRaw) ? $filesFileErrRaw : 0;
            $filesFileTmpRaw  = $filesFile['tmp_name'] ?? null;
            $filesFileTmpName = is_string($filesFileTmpRaw) ? $filesFileTmpRaw : '';
            if ($filesFileError !== 0 || !is_uploaded_file($filesFileTmpName)) {
                return new PwgError(103, 'Failed to move uploaded file.');
            }
            if (!$in = Filesystem::tryFopen($filesFileTmpName, 'rb')) {
                return new PwgError(101, 'Failed to open input stream.');
            }
        } else {
            if (!$in = Filesystem::tryFopen('php://input', 'rb')) {
                return new PwgError(101, 'Failed to open input stream.');
            }
        }
        if (is_resource($in) && is_resource($out)) {
            while ($buff = fread($in, 4096)) {
                fwrite($out, $buff);
            }
        }
        if (is_resource($out)) {
            fclose($out);
        }
        if (is_resource($in)) {
            fclose($in);
        }
        $addStatus = 'add';
        if (!$chunks || $chunk === $chunks - 1) {
            rename("{$filePath}.part", $filePath);
            if (isset($params['format_of'])) {
                $formatOfId = is_numeric($params['format_of']) ? (int) $params['format_of'] : 0;
                $image      = $this->imageRepository->findById($formatOfId);
                if ($image === null) {
                    return new PwgError(404, __FUNCTION__ . ' : image_id not found');
                }
                $srcImage  = SrcImage::fromImage($image);
                $addStatus = $this->uploadService->addFormat($filePath, $formatExt ?? '', (string) $image->id->value);
                return ['image_id' => $image->id->value, 'src' => DerivativeImage::thumbUrl($srcImage), 'square_src' => DerivativeImage::url(ImageStdParams::getByType(DerivativeSize::Square->value), $srcImage), 'name' => $image->name, 'add_status' => $addStatus];
            }
            $name          = stripslashes(is_string($params['name'] ?? null) ? $params['name'] : '');
            $idImage       = null;
            $pCategory     = is_array($params['category']) ? $params['category'] : [];
            $pCategoryInt  = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $pCategory);
            $pCategoryFirst = $pCategoryInt[0] ?? 0;
            if ($params['update_mode']) {
                $idImage = $this->imageRepository->findIdInCategoryByFile($pCategoryFirst, $name);
                if ($idImage !== null) {
                    $addStatus = 'update';
                }
            }
            $imageId        = $this->uploadService->addUploadedFile($filePath, $name, $pCategoryInt, is_numeric($params['level']) ? (int) $params['level'] : null, $idImage);
            $catRepo2       = $this->categoryRepository;
            $imageInfos     = $this->imageRepository->findById($imageId);
            $categoryInfos  = ['nb_photos' => $catRepo2->countImagesByCategoryId($pCategoryFirst)];
            $nbPhotosLounge = $this->imageRepository->countLoungeInCategoryNotAssociated($pCategoryFirst);
            $categoryName   = $this->htmlService->getCatDisplayNameFromId($pCategoryFirst, null);
            if ($imageInfos === null) {
                return null;
            }
            $srcImage = SrcImage::fromImage($imageInfos);
            return ['image_id' => $imageId, 'src' => DerivativeImage::thumbUrl($srcImage), 'square_src' => DerivativeImage::url(ImageStdParams::getByType(DerivativeSize::Square->value), $srcImage), 'name' => $imageInfos->name, 'category' => ['id' => $pCategoryFirst, 'nb_photos' => $categoryInfos['nb_photos'] + $nbPhotosLounge, 'label' => $categoryName], 'add_status' => $addStatus];
        }
        return null;
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Upload photo by chunks in random order. $_FILES[file] for upload; start with chunk 0.', tags: ['images'])]
    public function uploadAsync(array $params, PwgServer &$service): mixed
    {
        $logger = LoggerRegistry::current();
        $pOriginalSum = is_string($params['original_sum'] ?? null) ? $params['original_sum'] : '';
        if (!preg_match('/^[a-fA-F0-9]{32}$/', $pOriginalSum)) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid original_sum');
        }
        $pImageIdAsync = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $pChunk        = is_numeric($params['chunk']) ? (int) $params['chunk'] : 0;
        $pChunks       = is_numeric($params['chunks']) ? (int) $params['chunks'] : 0;
        if ($pImageIdAsync > 0 && !$this->imageRepository->existsById($pImageIdAsync)) {
            return new PwgError(404, __FUNCTION__ . ' : image_id not found');
        }
        $pUserId = (string) CurrentUser::get()->id;
        $outputFilepathPrefix  = Config::uploadDir() . '/buffer/' . $pOriginalSum . '-u' . $pUserId;
        $chunkfilePathPattern  = $outputFilepathPrefix . '-%03uof%03u.chunk';
        $chunkfilePath         = sprintf($chunkfilePathPattern, $pChunk + 1, $pChunks);
        if (!Filesystem::mkgetdir(dirname($chunkfilePath), Filesystem::FLAG_DEFAULT & ~Filesystem::FLAG_DIE_ON_ERROR)) {
            return new PwgError(500, 'error during buffer directory creation');
        }
        StringUtil::secureDirectory(dirname($chunkfilePath));
        $filesFile2RawArr  = $_FILES['file'] ?? null;
        $filesFile2        = is_array($filesFile2RawArr) ? $filesFile2RawArr : [];
        $filesFile2TmpRaw  = $filesFile2['tmp_name'] ?? null;
        $filesFile2TmpName = is_string($filesFile2TmpRaw) ? $filesFile2TmpRaw : '';
        $chunkRoot     = $this->paths->root . Config::uploadDir();
        $chunkAbsPath  = $this->paths->root . ltrim(str_replace(['\\', '/./'], ['/', '/'], $chunkfilePath), '/');
        $chunkRelPath  = StorageRegistry::stripRoot($chunkRoot, $chunkAbsPath);
        $chunkStream   = fopen($filesFile2TmpName, 'rb');
        if ($chunkStream !== false) {
            StorageRegistry::disk('uploads')->writeStream($chunkRelPath, $chunkStream);
            fclose($chunkStream);
        }
        $logger->debug(__FUNCTION__ . ' uploaded ' . $chunkfilePath);
        $chunkMd5  = md5_file($chunkfilePath);
        $pChunkSum = is_string($params['chunk_sum'] ?? null) ? $params['chunk_sum'] : '';
        if ($chunkMd5 !== $pChunkSum) {
            unlink($chunkfilePath);
            $logger->error(__FUNCTION__ . ' ' . $chunkfilePath . ' MD5 checksum mismatched');
            return new PwgError(500, 'MD5 checksum chunk file mismatched');
        }
        $chunkIdsUploaded = [];
        for ($i = 1; $i <= $pChunks; $i++) {
            $chunkfile = sprintf($chunkfilePathPattern, $i, $pChunks);
            if (file_exists($chunkfile) && ($fp = fopen($chunkfile, 'rb')) !== false) {
                $chunkIdsUploaded[] = $i;
                fclose($fp);
            }
        }
        if ($pChunks !== count($chunkIdsUploaded)) {
            $logger->debug(__FUNCTION__ . ' all chunks are not uploaded yet, exit for now');
            return ['message' => 'chunks uploaded = ' . implode(',', $chunkIdsUploaded)];
        }
        $logger->debug(__FUNCTION__ . ' ' . $pOriginalSum . ' ' . $pChunks . ' chunks available, try now to get lock');
        $outputFilepath = $outputFilepathPrefix . '.merged';
        if (file_exists($outputFilepath) && ($fp = fopen($outputFilepath, 'rb')) !== false) {
            fclose($fp);
            $logger->error(__FUNCTION__ . ' ' . $outputFilepath . ' already exists');
            return ['message' => 'chunks uploaded = ' . implode(',', $chunkIdsUploaded)];
        }
        $fp = fopen($outputFilepath, 'wb');
        if (!$fp) {
            $logger->error(__FUNCTION__ . ' unable to create merge file');
            return new PwgError(500, 'error while creating merged ' . $chunkfilePath);
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            $logger->error(__FUNCTION__ . ' unable to obtain lock');
            return new PwgError(500, 'error while locking merged ' . $chunkfilePath);
        }
        $logger->debug(__FUNCTION__ . ' lock obtained to merge chunks');
        foreach ($chunkIdsUploaded as $chunkId) {
            $chunkfilePath = sprintf($chunkfilePathPattern, $chunkId, is_numeric($params['chunks']) ? (int) $params['chunks'] : 0);
            if (!file_exists($chunkfilePath)) {
                $logger->error(__FUNCTION__ . ' ' . $chunkfilePath . ' already merged');
                flock($fp, LOCK_UN);
                fclose($fp);
                return ['message' => 'chunks uploaded = ' . implode(',', $chunkIdsUploaded)];
            }
            $chunkdata = file_get_contents($chunkfilePath);
            if ($chunkdata === false || fwrite($fp, $chunkdata) === false) {
                $logger->error(__FUNCTION__ . ' error merging chunk ' . $chunkfilePath);
                flock($fp, LOCK_UN);
                fclose($fp);
                Filesystem::tryUnlink($outputFilepath);
                return new PwgError(500, 'error while merging chunk ' . $chunkId);
            }
            $logger->debug(__FUNCTION__ . ' original_sum=' . $pOriginalSum . ', chunk ' . $chunkId . '/' . $pChunks . ' merged');
            unlink($chunkfilePath);
        }
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $logger->debug(__FUNCTION__ . ' merged file ' . $outputFilepath . ' saved');
        $mergedMd5 = md5_file($outputFilepath);
        if ($mergedMd5 !== $pOriginalSum) {
            unlink($outputFilepath);
            $logger->error(__FUNCTION__ . ' ' . $outputFilepath . ' MD5 checksum mismatched!');
            return new PwgError(500, 'MD5 checksum merged file mismatched');
        }
        $logger->debug(__FUNCTION__ . ' ' . $outputFilepath . ' MD5 checksum OK');
        $pFilename       = is_scalar($params['filename']) ? (string) $params['filename'] : null;
        $pCategoryAsync  = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['category']) ? $params['category'] : []);
        $pLevelAsync     = is_numeric($params['level']) ? (int) $params['level'] : null;
        $pImageIdUpload  = is_numeric($params['image_id']) ? (int) $params['image_id'] : null;
        $imageId         = $this->uploadService->addUploadedFile($outputFilepath, $pFilename, $pCategoryAsync, $pLevelAsync, $pImageIdUpload, $pOriginalSum);
        $logger->debug(__FUNCTION__ . ' image_id after add_uploaded_file = ' . $imageId);
        if (isset($params['tag_ids']) && $params['tag_ids'] !== '') {
            $this->tagAdminService->setTags(explode(',', is_string($params['tag_ids']) ? $params['tag_ids'] : ''), $imageId);
        }
        $update = [];
        foreach (['name', 'author', 'comment', 'date_creation'] as $key) {
            if (isset($params[$key])) {
                $update[$key] = $params[$key];
            }
        }
        if (count($update) > 0) {
            $this->imageRepository->updateById($imageId, $update);
        }
        $this->userAdminService->invalidateUserCache();
        if (CurrentUser::isInitialized() && !empty($params['level']) && $params['level'] > (CurrentUser::get()->rawAttributes['level'] ?? 0)) {
            CurrentUser::get()->rawAttributes['level'] = $params['level'];
        }
        $now = time();
        $globBufferResult = glob(Config::uploadDir() . '/buffer/' . '*.chunk');
        foreach ($globBufferResult !== false ? $globBufferResult : [] as $file) {
            $mtime = filemtime($file);
            if (is_file($file) && $mtime !== false && $now - $mtime >= 60 * 60 * 24 * 7) {
                unlink($file);
            }
        }
        foreach ((($mergedGlob = glob(Config::uploadDir() . '/buffer/' . '*.merged')) !== false ? $mergedGlob : []) as $file) {
            $mtime = filemtime($file);
            if (is_file($file) && $mtime !== false && $now - $mtime >= 60 * 60 * 24 * 7) {
                unlink($file);
            }
        }
        return $service->invoke('pwg.images.getInfo', ['image_id' => $imageId]);
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    #[ApiMethod(summary: 'Checks existence of images. Give md5sum_list if uniqueness_mode=md5sum, filename_list if uniqueness_mode=filename.', tags: ['images'])]
    public function exist(array $params, PwgServer $service): array
    {
        $splitPattern = '/[\s,;\|]/';
        $result       = [];
        if (Config::uniquenessMode() === 'md5sum') {
            $md5sumsResult = preg_split($splitPattern, is_string($params['md5sum_list'] ?? null) ? $params['md5sum_list'] : '', -1, PREG_SPLIT_NO_EMPTY);
            $md5sums  = $md5sumsResult !== false ? $md5sumsResult : [];
            $idOfMd5  = $this->imageRepository->findIdByMd5sumMap($md5sums);
            foreach ($md5sums as $md5sum) {
                $result[$md5sum] = $idOfMd5[$md5sum] ?? null;
            }
        } elseif (Config::uniquenessMode() === 'filename') {
            $filenamesResult = preg_split($splitPattern, is_string($params['filename_list'] ?? null) ? $params['filename_list'] : '', -1, PREG_SPLIT_NO_EMPTY);
            $filenames = $filenamesResult !== false ? $filenamesResult : [];
            $idOfFile  = $this->imageRepository->findIdByFilenameMap($filenames);
            foreach ($filenames as $filename) {
                $result[$filename] = $idOfFile[$filename] ?? null;
            }
        }
        return $result;
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Search for image ids matching filenames. filename_list is JSON-encoded unique_id:filename. Returns unique_id:image_id.', tags: ['images'])]
    public function formatsSearchImage(array $params, PwgServer $service): mixed
    {
        $candidates = json_decode(stripslashes(is_string($params['filename_list'] ?? null) ? $params['filename_list'] : ''), true);
        $uniqueFilenamesDb = [];
        foreach ($this->imageRepository->findAllIdFilename() as $row) {
            $filenameWoExt = StringUtil::getFilenameWoExtension((string) $row->file);
            $uniqueFilenamesDb[$filenameWoExt][] = $row->id->value;
        }
        $formatExtensions = Config::formatExtensions();
        usort($formatExtensions, fn (mixed $a, mixed $b): int => strlen((string) $b) - strlen((string) $a));
        /** @var array<string, list<string>> $formatDb */
        $formatDb = [];
        foreach ($this->imageRepository->findAllFormats() as $row) {
            $fmtImageId = is_scalar($row['image_id'] ?? null) ? (string) $row['image_id'] : '';
            $fmtExtVal  = is_string($row['ext'] ?? null) ? $row['ext'] : '';
            $formatDb[$fmtImageId][] = $fmtExtVal;
        }
        $result        = [];
        $candidatesArr = is_array($candidates) ? $candidates : [];
        foreach ($candidatesArr as $formatExternalId => $formatFilename) {
            $fmtExternalIdStr  = (string) $formatExternalId;
            $fmtFilenameStr    = is_scalar($formatFilename) ? (string) $formatFilename : '';
            $candidateFilenameWoExt = null;
            if (preg_match('/^(.*?)\.(' . implode('|', Config::formatExtensions()) . ')$/', $fmtFilenameStr, $matches)) {
                $candidateFilenameWoExt = $matches[1];
            }
            if ($candidateFilenameWoExt === null || $candidateFilenameWoExt === '') {
                $result[$fmtExternalIdStr] = ['status' => 'not found'];
                continue;
            }
            if (isset($uniqueFilenamesDb[$candidateFilenameWoExt])) {
                if (count($uniqueFilenamesDb[$candidateFilenameWoExt]) > 1) {
                    $result[$fmtExternalIdStr] = ['status' => 'multiple'];
                    continue;
                }
                $imgIdStr  = (string) $uniqueFilenamesDb[$candidateFilenameWoExt][0];
                $multForm  = false;
                if (isset($formatDb[$imgIdStr])) {
                    $fmtExt = pathinfo($fmtFilenameStr, PATHINFO_EXTENSION);
                    if (array_search($fmtExt, $formatDb[$imgIdStr]) !== false) {
                        $multForm = true;
                    }
                }
                $result[$fmtExternalIdStr] = ['status' => 'found', 'image_id' => $uniqueFilenamesDb[$candidateFilenameWoExt][0], 'format_exist' => $multForm];
                continue;
            }
            $result[$fmtExternalIdStr] = ['status' => 'not found'];
        }
        return $result;
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Remove a format', tags: ['images'])]
    public function formatsDelete(array $params, PwgServer $service): PwgError|bool
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!is_array($params['format_id'])) {
            $params['format_id'] = (($fmtSplit = preg_split('/[\s,;\|]/', is_string($params['format_id']) ? $params['format_id'] : '', -1, PREG_SPLIT_NO_EMPTY)) !== false ? $fmtSplit : []);
        }
        $params['format_id'] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['format_id']);
        $formatIds = array_filter($params['format_id'], fn (int $v): bool => $v >= 0);
        $ok        = true;
        $imgRepo   = $this->imageRepository;
        /** @var array<string, list<string>> $formatsOf */
        $formatsOf = [];
        /** @var list<string> $imageIds */
        $imageIds  = [];
        foreach ($imgRepo->findFormatsByFormatIds(array_map(intval(...), $formatIds)) as $row) {
            $rowImageId = is_scalar($row['image_id'] ?? null) ? (string) $row['image_id'] : '';
            $rowExt     = is_string($row['ext'] ?? null) ? $row['ext'] : '';
            if (!isset($formatsOf[$rowImageId])) {
                $imageIds[] = $rowImageId;
                $formatsOf[$rowImageId] = [];
            }
            $formatsOf[$rowImageId][] = $rowExt;
        }
        if (count($imageIds) === 0) {
            return new PwgError(404, 'No format found for the id(s) given');
        }
        foreach ($imgRepo->findByIds(array_map(intval(...), $imageIds)) as $img) {
            $rowPath = $img->path->value;
            $rowId   = (string) $img->id->value;
            if (UrlService::urlIsRemote($rowPath)) {
                continue;
            }
            $imagePath = StringUtil::getElementPath(['path' => $rowPath]);
            $files     = [];
            if (isset($formatsOf[$rowId])) {
                foreach ($formatsOf[$rowId] as $formatExt) {
                    $files[] = StringUtil::originalToFormat($imagePath, $formatExt);
                }
            }
            foreach ($files as $path) {
                if (is_file($path) && !unlink($path)) {
                    throw new \RuntimeException('"' . $path . '" cannot be removed');
                }
            }
        }
        $imgRepo->deleteFormatsByFormatIds(array_map(intval(...), $formatIds));
        $this->userAdminService->invalidateUserCache();
        return $ok;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Checks if you have updated version of your files for a given photo. Answer: missing, equals, or differs.', tags: ['images'])]
    public function checkFiles(array $params, PwgServer $service): PwgError|array
    {
        $checkImageId = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $path         = $this->imageRepository->findPathById($checkImageId);
        if ($path === null) {
            return new PwgError(404, 'image_id not found');
        }
        $ret = [];
        if (isset($params['file_sum'])) {
            $ret['file'] = md5_file($path) !== $params['file_sum'] ? 'differs' : 'equals';
        }
        return $ret;
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Changes properties of an image. single_value_mode controls whether the input fills empty values or replaces them.', tags: ['images'])]
    public function setInfo(array $params, PwgServer $service): mixed
    {
        if (isset($params['pwg_token']) && $this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $setImageId = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $image      = $this->imageRepository->findById($setImageId);
        if ($image === null) {
            return new PwgError(404, 'image_id not found');
        }
        $existingValues = [
            'name'          => $image->name,
            'author'        => $image->author,
            'comment'       => $image->comment,
            'level'         => $image->level,
            'date_creation' => $image->dateCreation?->value,
        ];
        $update              = [];
        $singleValueMode     = is_string($params['single_value_mode'] ?? null) ? $params['single_value_mode'] : '';
        $multipleValueMode   = is_string($params['multiple_value_mode'] ?? null) ? $params['multiple_value_mode'] : '';
        foreach (['name', 'author', 'comment', 'level', 'date_creation'] as $key) {
            if (isset($params[$key])) {
                if (!Config::allowHtmlDescriptions() || !isset($params['pwg_token'])) {
                    $params[$key] = strip_tags(is_scalar($params[$key]) ? (string) $params[$key] : '', '<b><strong><em><i>');
                }
                if ($singleValueMode === 'fill_if_empty') {
                    if (empty($existingValues[$key])) {
                        $update[$key] = $params[$key];
                    }
                } elseif ($singleValueMode === 'replace') {
                    $update[$key] = $params[$key];
                } else {
                    return new PwgError(500, '[ws_images_setInfo] invalid parameter single_value_mode "' . $singleValueMode . '", possible values are {fill_if_empty, replace}.');
                }
            }
        }
        if (isset($params['file'])) {
            if ($image->storageCategoryId !== null) {
                return new PwgError(500, '[ws_images_setInfo] updating "file" is forbidden on photos added by synchronization');
            }
            $update['file'] = strip_tags(is_string($params['file']) ? $params['file'] : '');
            if (empty($update['file'])) {
                unset($update['file']);
            }
        }
        if (count($update) > 0) {
            $this->imageRepository->updateById($setImageId, $update);
            $this->activityLogger->log(new ActivityEvent(ActivityObject::Photo, $setImageId, 'edit'));
        }
        if (isset($params['categories'])) {
            $this->addImageCategoryRelations($setImageId, is_string($params['categories']) ? $params['categories'] : '', $multipleValueMode === 'replace');
        }
        if (isset($params['tag_ids'])) {
            $tagIds = [];
            foreach (explode(',', is_string($params['tag_ids']) ? $params['tag_ids'] : '') as $candidate) {
                $candidate = trim($candidate);
                if (preg_match(ValidationPattern::ID, $candidate)) {
                    $tagIds[] = $candidate;
                }
            }
            if ($multipleValueMode === 'replace') {
                $this->tagAdminService->setTags($tagIds, $setImageId);
            } elseif ($multipleValueMode === 'append') {
                $this->tagAdminService->addTags($tagIds, [$setImageId]);
            } else {
                return new PwgError(500, '[ws_images_setInfo] invalid parameter multiple_value_mode "' . $multipleValueMode . '", possible values are {replace, append}.');
            }
        }
        if (isset($_REQUEST['tag_list'])) {
            if (isset($params['tag_ids'])) {
                return new PwgError(WsError::InvalidParam->value, 'Do not use tag_list and tag_ids at the same time.');
            }
            $requestTagList = is_array($_REQUEST['tag_list']) ? $_REQUEST['tag_list'] : [];
            foreach ($requestTagList as $idx => $tagCandidate) {
                $requestTagList[$idx] = strip_tags(stripslashes(is_string($tagCandidate) ? $tagCandidate : ''));
            }
            $tagList = $this->tagAdminService->getTagIds($requestTagList);
            $this->tagAdminService->setTags($tagList, $setImageId);
        }
        $this->userAdminService->invalidateUserCache();
        return null;
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Deletes image(s).', tags: ['images'])]
    public function delete(array $params, PwgServer $service): PwgError|int
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $delImageIdsRaw = $params['image_id'];
        if (!is_array($delImageIdsRaw)) {
            $delImageIdsRaw = (($delSplit = preg_split('/[\s,;\|]/', is_scalar($delImageIdsRaw) ? (string) $delImageIdsRaw : '', -1, PREG_SPLIT_NO_EMPTY)) !== false ? $delSplit : []);
        }
        $delImageIdsRaw = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $delImageIdsRaw);
        $imageIds       = array_filter($delImageIdsRaw, fn (int $v): bool => $v > 0);
        $ret            = $this->imageAdminService->deleteElements(array_values($imageIds), true);
        $this->userAdminService->invalidateUserCache();
        return $ret;
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Checks if Piwigo is ready for upload.', tags: ['images'])]
    public function checkUpload(mixed $params, PwgServer $service): mixed
    {
        $ret = [];
        $ret['message']        = $this->uploadService->readyForUploadMessage();
        $ret['ready_for_upload'] = ($ret['message'] === null || $ret['message'] === '');
        return $ret;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    #[ApiMethod(summary: 'Empty lounge, where images may be waiting before taking off.', tags: ['images'])]
    public function emptyLounge(array $params, PwgServer $service): array
    {
        return ['rows' => $this->categoryAdminService->emptyLounge()];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Notify Piwigo you have finished uploading a set of photos. It will empty the lounge, if any.', tags: ['images'])]
    public function uploadCompleted(array $params, PwgServer $service): PwgError|array
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $ucImageIdsRaw = $params['image_id'];
        if (!is_array($ucImageIdsRaw)) {
            $ucImageIdsRaw = (($ucSplit = preg_split('/[\s,;\|]/', is_scalar($ucImageIdsRaw) ? (string) $ucImageIdsRaw : '', -1, PREG_SPLIT_NO_EMPTY)) !== false ? $ucSplit : []);
        }
        $ucImageIdsRaw = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $ucImageIdsRaw);
        $imageIds      = array_values(array_filter($ucImageIdsRaw, fn (int $v): bool => $v > 0));
        $ucCategoryId  = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        $movedFromLounge  = $this->categoryAdminService->emptyLounge();
        $categoryInfos    = ['nb_photos' => $this->categoryRepository->countImagesByCategoryId($ucCategoryId)];
        $categoryName     = $this->htmlService->getCatDisplayNameFromId($ucCategoryId, null);
        $this->dispatcher->dispatch(new WsImagesUploadCompleted(['image_ids' => $imageIds, 'category_id' => $ucCategoryId, 'moved_from_lounge' => $movedFromLounge]));
        return ['moved_from_lounge' => $movedFromLounge, 'category' => ['id' => $ucCategoryId, 'nb_photos' => $categoryInfos['nb_photos'], 'label' => $categoryName]];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Set md5sum column, by blocks. Returns how many md5sums were added and how many are remaining.', tags: ['images'])]
    public function setMd5sum(array $params, PwgServer $service): PwgError|array
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $noMd5sumIds    = $this->imageAdminService->getPhotosNoMd5sum();
        $addedCount     = 0;
        if (count($noMd5sumIds) > 0) {
            $md5sumIdsToAdd = array_slice($noMd5sumIds, 0, is_numeric($params['block_size']) ? (int) $params['block_size'] : null);
            $addedCount     = $this->imageAdminService->addMd5sum($md5sumIdsToAdd);
        }
        return ['nb_added' => $addedCount, 'nb_no_md5sum' => count($this->imageAdminService->getPhotosNoMd5sum())];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Sync metadatas, by blocks. Returns how many images were synchronized.', tags: ['images'])]
    public function syncMetadata(array $params, PwgServer $service): PwgError|array
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $syncImageIdsRaw = $params['image_id'];
        if (!is_array($syncImageIdsRaw)) {
            $syncImageIdsRaw = (($syncSplit = preg_split('/[\s,;\|]/', is_scalar($syncImageIdsRaw) ? (string) $syncImageIdsRaw : '', -1, PREG_SPLIT_NO_EMPTY)) !== false ? $syncSplit : []);
        }
        $imageIds = [];
        foreach ($syncImageIdsRaw as $imageId) {
            $imageId = trim(is_scalar($imageId) ? (string) $imageId : '');
            if (!preg_match(ValidationPattern::ID, $imageId)) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid image_id "' . $imageId . '"');
            }
            $imageIds[] = $imageId;
        }
        if (empty($imageIds)) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid image_id (no value after filters)');
        }
        $imageIdsInt = array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $imageIds);
        $imageIds    = $this->imageRepository->findExistingIdsAmong($imageIdsInt);
        if (empty($imageIds)) {
            return new PwgError(403, 'No image found');
        }
        $this->metadataAdminService->syncMetadata($imageIds);
        return ['nb_synchronized' => count($imageIds)];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Deletes orphans, by blocks. Returns how many orphans were deleted and how many are remaining.', tags: ['images'])]
    public function deleteOrphans(array $params, PwgServer $service): PwgError|array
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $orphanIdsToDelete = array_slice($this->imageAdminService->getOrphans(), 0, is_numeric($params['block_size']) ? (int) $params['block_size'] : null);
        $deletedCount      = $this->imageAdminService->deleteElements($orphanIdsToDelete, true);
        $this->userAdminService->invalidateUserCache();
        return ['nb_deleted' => $deletedCount, 'nb_orphans' => count($this->imageAdminService->getOrphans())];
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Manage image-album associations. action: associate (add), dissociate (remove), or move (dissociate from others + add).', tags: ['images'])]
    public function setCategory(array $params, PwgServer $service): mixed
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $scCategoryId = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        $scImageIds   = is_array($params['image_id']) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['image_id']) : [];
        if (!$this->categoryRepository->existsById($scCategoryId)) {
            return new PwgError(404, 'category_id not found');
        }
        $scAction = is_string($params['action'] ?? null) ? $params['action'] : '';
        if ($scAction === 'associate') {
            $this->categoryAdminService->associateImagesToCategories($scImageIds, [$scCategoryId]);
        } elseif ($scAction === 'dissociate') {
            $this->categoryAdminService->dissociateImagesFromCategory($scImageIds, (string) $scCategoryId);
        } elseif ($scAction === 'move') {
            $this->categoryAdminService->moveImagesToCategories($scImageIds, [$scCategoryId]);
        }
        $this->userAdminService->invalidateUserCache();
        return null;
    }
}
