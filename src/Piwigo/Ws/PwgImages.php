<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentService;
use Piwigo\Comment\Projection\CommentSummary;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\StringHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\ValidationPattern;
use Piwigo\Core\WsError;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\AdvisorySessionLock;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Event\Picture\RenderElementName;
use Piwigo\Event\Picture\WsImagesUploadCompleted;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImagePathHelper;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\ImageUniquenessColumn;
use Piwigo\Metadata\MetadataService;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Rate\RateService;
use Piwigo\Search\SearchService;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Tag\TagService;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\Encoder\PwgResponseEncoder;
use Piwigo\Ws\Request\ChunkedUploadRequest;
use Piwigo\Ws\Request\TagListRequest;
use Piwigo\Ws\Request\UploadedFileRequest;

/**
 * `pwg.images.*` WS methods (26 registrations) -- registered via callable
 * arrays in src/Piwigo/Ws/WsDefaultMethods.php. The 3 private helpers
 * (addImageCategoryRelations/mergeChunks/removeChunks) are internal,
 * never WS-registered themselves.
 */
final class PwgImages
{
    /**
     * Advisory-lock acquisition timeout for add()'s check_uniqueness race
     * fix below -- generous enough to cover a concurrent upload's own
     * full image-processing pipeline (resize, representative generation)
     * rather than just a DB round-trip.
     */
    private const int UPLOAD_UNIQUENESS_LOCK_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly PermissionService $permissionService,
        private readonly CategoryService $categoryService,
        private readonly SearchService $searchService,
        private readonly TagService $tagService,
        private readonly ImageService $imageService,
        private readonly ActivityService $activityService,
        private readonly RateService $rateService,
        private readonly MetadataService $metadataService,
        private readonly CommentService $commentService,
        private readonly UploadService $uploadService,
        private readonly CurrentConfig $currentConfig,
        private readonly UrlServiceInterface $urlService,
        private readonly EventDispatcher $eventDispatcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly Lang $lang,
        private readonly CurrentLogger $currentLogger,
        private readonly CurrentUser $currentUser,
        private readonly AccessControl $accessControl,
        private readonly HtmlService $htmlService,
        private readonly Paths $paths,
        private readonly StorageRegistry $storageRegistry,
        private readonly ImageRepository $imageRepository,
        private readonly ImageStdParams $imageStdParams,
        private readonly WsHelper $wsHelper,
        private readonly DbCredentials $dbCredentials,
    ) {}

    /**
     * Sets associations of an image
     * @param string $categories_string - "cat_id[,rank];cat_id[,rank]"
     * @param bool $replace_mode - removes old associations
     */
    private function addImageCategoryRelations(ImageId $image_id, string $categories_string, bool $replace_mode = false): true|PwgError
    {
        $categoryService = $this->categoryService;

        // let's add links between the image and the categories
        //
        // $params['categories'] should look like 123,12;456,auto;789 which means:
        //
        // 1. associate with category 123 on rank 12
        // 2. associate with category 456 on automatic rank
        // 3. associate with category 789 on automatic rank
        $cat_ids = [];
        $rank_on_category = [];
        $search_current_ranks = false;

        if ($categories_string === '') {
            if ($replace_mode) {
                $this->imageService->deleteImageCategoryRowsForImageIds([$image_id->value]);
                $categoryService->updateCategory([]);
            }
            return true;
        }
        $tokens = explode(';', $categories_string);
        foreach ($tokens as $token) {
            $token_parts = explode(',', $token);
            $cat_id = $token_parts[0];
            $rank = $token_parts[1] ?? 'auto';

            if (! (bool) preg_match('/^\d+$/', $cat_id)) {
                continue;
            }

            $cat_ids[] = $cat_id;
            $rank_on_category[$cat_id] = $rank;

            if ($rank === 'auto') {
                $search_current_ranks = true;
            }
        }

        $cat_ids = array_unique($cat_ids);

        if (count($cat_ids) === 0) {
            if ($replace_mode) {
                $this->imageService->deleteImageCategoryRowsForImageIds([$image_id->value]);
                $categoryService->updateCategory([]);
            }
            return true;
        }

        // native int under DBAL -- cast to string so array_diff() below
        // (string-based comparison against $cat_ids, which comes from
        // explode()-derived string tokens) keeps comparing like-for-like.
        $db_cat_ids = array_map(strval(...), $categoryService->getExistingIds(array_values(array_map(intval(...), $cat_ids))));

        $unknown_cat_ids = array_diff($cat_ids, $db_cat_ids);
        if (count($unknown_cat_ids) !== 0) {
            return new PwgError(
                500,
                '[ws_add_image_category_relations] the following categories are unknown: ' . implode(', ', $unknown_cat_ids)
            );
        }

        $to_update_cat_ids = [];

        // in case of replace mode, we first check the existing associations
        // native int under DBAL -- same string-cast rationale as
        // $db_cat_ids above.
        $existing_cat_ids = array_map(strval(...), $this->imageService->getCategoryIdsForImage($image_id));

        if ($replace_mode) {
            $to_remove_cat_ids = array_values(array_diff($existing_cat_ids, $cat_ids));
            if (count($to_remove_cat_ids) > 0) {
                $this->imageService->deleteImageCategoryLinksForCategoryIds($image_id, $to_remove_cat_ids);
                $categoryService->updateCategory($to_remove_cat_ids);
            }
        }

        $new_cat_ids = array_diff($cat_ids, $existing_cat_ids);
        if (count($new_cat_ids) === 0) {
            return true;
        }

        if ($search_current_ranks) {
            $current_rank_of = $this->imageService->getMaxRanksByCategory($new_cat_ids);

            foreach ($new_cat_ids as $cat_id) {
                if (! isset($current_rank_of[$cat_id])) {
                    $current_rank_of[$cat_id] = 0;
                }

                if ($rank_on_category[$cat_id] === 'auto') {
                    $rank_on_category[$cat_id] = $current_rank_of[$cat_id] + 1;
                }
            }
        }

        $inserts = [];

        foreach ($new_cat_ids as $cat_id) {
            $inserts[] = [
                'image_id' => $image_id->value,
                'category_id' => $cat_id,
                'rank' => $rank_on_category[$cat_id],
            ];
        }

        $this->imageService->insertImageCategoryLinks($inserts);

        $categoryService->updateCategory($new_cat_ids);
        return true;
    }

    /**
     * Merge chunks added by pwg.images.addChunk
     */
    private function mergeChunks(string $output_filepath, string $original_sum, string $type): ?PwgError
    {
        $logger = $this->currentLogger->get();

        $logger->debug('[merge_chunks] input parameter $output_filepath : ' . $output_filepath, 'WS');

        if (is_file($output_filepath)) {
            unlink($output_filepath);

            if (is_file($output_filepath)) {
                return new PwgError(500, '[merge_chunks] error while trying to remove existing ' . $output_filepath);
            }
        }

        $upload_dir_conf = $this->paths->root . $this->currentConfig->uploadDir();
        $upload_dir = $upload_dir_conf . '/buffer';
        $pattern = '/' . $original_sum . '-' . $type . '/';
        $chunks = [];

        if ((bool) ($handle = opendir($upload_dir))) {
            while (false !== ($file = readdir($handle))) {
                if ((bool) preg_match($pattern, $file)) {
                    $logger->debug($file, 'WS');
                    $chunks[] = $upload_dir . '/' . $file;
                }
            }
            closedir($handle);
        }

        sort($chunks);

        if (function_exists('memory_get_usage')) {
            $logger->debug('[merge_chunks] memory_get_usage before loading chunks: ' . memory_get_usage(), 'WS');
        }

        $i = 0;

        foreach ($chunks as $chunk) {
            $string = file_get_contents($chunk);

            if (function_exists('memory_get_usage')) {
                $logger->debug('[merge_chunks] memory_get_usage on chunk ' . ++$i . ': ' . memory_get_usage(), 'WS');
            }

            if ($string === false || ! (bool) file_put_contents($output_filepath, $string, FILE_APPEND)) {
                return new PwgError(500, '[merge_chunks] error while writting chunks for ' . $output_filepath);
            }

            unlink($chunk);
        }

        if (function_exists('memory_get_usage')) {
            $logger->debug('[merge_chunks] memory_get_usage after loading chunks: ' . memory_get_usage(), 'WS');
        }

        return null;
    }

    /**
     * Deletes chunks added with pwg.images.addChunk
     * @param string $original_sum
     * @param string $type
     *
     * Function introduced for Piwigo 2.4 and the new "multiple size"
     * (derivatives) feature. As we only need the biggest sent photo as
     * "original", we remove chunks for smaller sizes. We can't make it earlier
     * in ws_images_add_chunk because at this moment we don't know which $type
     * will be the biggest (we could remove the thumb, but let's use the same
     * algorithm)
     */
    private function removeChunks($original_sum, string $type): void
    {

        $upload_dir_conf = $this->paths->root . $this->currentConfig->uploadDir();
        $upload_dir = $upload_dir_conf . '/buffer';
        $pattern = '/' . $original_sum . '-' . $type . '/';
        $chunks = [];

        if ((bool) ($handle = opendir($upload_dir))) {
            while (false !== ($file = readdir($handle))) {
                if ((bool) preg_match($pattern, $file)) {
                    $chunks[] = $upload_dir . '/' . $file;
                }
            }
            closedir($handle);
        }

        foreach ($chunks as $chunk) {
            unlink($chunk);
        }
    }

    /**
     * API method
     * Adds a comment to an image
     * @param array{image_id: int, author: string, content: string, key: string, ...} $params
     *    image_id: WsParamType::ID, mandatory -- always a plain int. author/content/
     *    key have no WS_TYPE flag, but PwgServer::invoke() rejects an array
     *    value for any registered param without WsParamFlag::ACCEPT_ARRAY, so
     *    they're always plain strings too (author has a string default,
     *    content/key are mandatory)
     * @return PwgError|array{comment: PwgNamedStruct}
     */
    public function addComment(array $params, PwgServer $service): PwgError|array
    {

        if (! $this->currentConfig->activateComments()) {
            return new PwgError(403, 'Comments are disabled');
        }

        $permissionCriteria = $this->permissionService->getPermissionCriteria();

        if (! $this->imageService->isImageCommentableWithCondition(ImageId::from($params['image_id']), $permissionCriteria)) {
            return new PwgError(WsError::INVALID_PARAM, 'Invalid image_id');
        }

        $comm = [
            'author' => trim($params['author']),
            'content' => trim($params['content']),
            'image_id' => $params['image_id'],
        ];

        $infos = [];
        $comment_action = $this->commentService
            ->insertComment($comm, $params['key'], $infos);

        switch ($comment_action) {
            case 'reject':
                $infos[] = $this->lang->t('Your comment has NOT been registered because it did not pass the validation rules');
                return new PwgError(403, implode('; ', $infos));

            case 'validate':
            case 'moderate':
                $ret = [
                    'id' => $comm['id'] ?? 0,
                    'validation' => $comment_action === 'validate',
                ];
                return [
                    'comment' => new PwgNamedStruct($ret),
                ];

            default:
                return new PwgError(500, 'Unknown comment action ' . $comment_action);
        }
    }

    /**
     * API method
     * Returns detailed information for an element
     * @param array{image_id: int, comments_page: int, comments_per_page: int, ...} $params
     *    all three are WsParamType::INT|WsParamType::POSITIVE (image_id: WsParamType::ID) --
     *    always plain ints by the time this runs (comments_page/
     *    comments_per_page have defaults, so always present too)
     * @return PwgError|array<string, mixed>
     */
    public function getInfo(array $params, PwgServer $service): PwgError|array
    {

        $image_row = $this->imageService->getRowWithCondition(
            ImageId::from($params['image_id']),
            $this->permissionService->getPermissionCriteria()
        );
        if ($image_row === null) {
            return new PwgError(404, 'image_id not found');
        }

        // id is the Tables::images() primary key, guaranteed numeric; captured
        // before array_merge() below widens every value of $image_row to mixed.
        assert(is_numeric($image_row['id']));
        $image_id = (int) $image_row['id'];

        // array_merge() with WsHelper::stdGetUrls()'s mixed-valued return widens
        // PHPStan's tracked shape for every other key of the original
        // fetchAssociative() row -- restate the columns this function reads
        // below (id: Tables::images() NOT NULL primary key, native int under
        // DBAL; file: NOT NULL; name/comment/rating_score: nullable) plus an
        // open tail for the rest of the row and the page_url/element_url/
        // download_url/derivatives keys WsHelper::stdGetUrls() injects.
        /** @var array{id: int, file: string, name: string|null, comment: string|null, rating_score: string|null, ...} $image_row */
        $image_row = array_merge($image_row, $this->wsHelper->stdGetUrls($image_row, $this->urlService));

        $image_row['name_raw'] = $image_row['name'];
        $nameEvent = $this->eventDispatcher->dispatchChange(new RenderElementName(is_string($image_row['name']) ? $image_row['name'] : '', __FUNCTION__));
        $image_row['name'] = strip_tags($nameEvent->elementName);

        $image_row['comment_raw'] = $image_row['comment'];
        $descriptionEvent = $this->eventDispatcher->dispatchChange(new RenderElementDescription(is_string($image_row['comment']) ? $image_row['comment'] : '', __FUNCTION__));
        $image_row['comment'] = $descriptionEvent->elementDescription;

        // -------------------------------------------------------- related categories
        $related_category_rows = $this->imageService->getRelatedCategoriesForImage(
            ImageId::from($image_id),
            $this->permissionService->getPermissionCriteria()
        );

        $is_commentable = false;
        $related_categories = [];
        foreach ($related_category_rows as $row) {
            if ((bool) $row['commentable']) {
                $is_commentable = true;
            }
            unset($row['commentable']);

            $row['url'] = $this->urlService->makeIndexUrl(
                [
                    'category' => $row,
                ]
            );

            $row['page_url'] = $this->urlService->makePictureUrl(
                [
                    'image_id' => $image_row['id'],
                    'image_file' => $image_row['file'],
                    'category' => $row,
                ]
            );

            $row['id'] = is_numeric($row['id']) ? (int) $row['id'] : 0;

            $nameEvent = $this->eventDispatcher->dispatchChange(new RenderCategoryName(is_string($row['name']) ? $row['name'] : '', __FUNCTION__));
            $row['name'] = strip_tags($nameEvent->categoryName);

            $related_categories[] = $row;
        }
        usort($related_categories, CategoryService::compareByGlobalRank(...));

        if ($related_categories === [] and ! $this->accessControl->isAdmin()) {
            // photo might be in the lounge? or simply orphan. A standard user should not get
            // info. An admin should still be able to get info.
            return new PwgError(401, 'Access denied');
        }

        // -------------------------------------------------------------- related tags
        $related_tags = $this->tagService
            ->getCommonTags([$image_id], -1, $this->htmlService);
        foreach ($related_tags as $i => $tag) {
            $tag['url'] = $this->urlService->makeIndexUrl(
                [
                    'tags' => [$tag],
                ]
            );
            $tag['page_url'] = $this->urlService->makePictureUrl(
                [
                    'image_id' => $image_row['id'],
                    'image_file' => $image_row['file'],
                    'tags' => [$tag],
                ]
            );

            unset($tag['counter']);
            $related_tags[$i] = $tag;
        }

        // ------------------------------------------------------------- related rates
        $rating_score_raw = $image_row['rating_score'];
        $rating = [
            'score' => $rating_score_raw,
            'count' => 0,
            'average' => null,
        ];
        if (isset($rating['score'])) {
            $rate_summary = $this->rateService->getRateSummaryForElement(ImageId::from($image_id));

            assert(is_numeric($rating_score_raw));
            $rating['score'] = (float) $rating_score_raw;
            $rating['average'] = $rate_summary->average ?? 0.0;
            $rating['count'] = $rate_summary->count;
        }

        // ---------------------------------------------------------- related comments
        $related_comments = [];

        $only_validated_comments = ! $this->accessControl->isAdmin();
        $commentService = $this->commentService;

        $nb_comments = $commentService->countForImage(ImageId::from($image_id), $only_validated_comments);

        if ($nb_comments > 0 and $params['comments_per_page'] > 0) {
            $related_comments = array_map(
                static fn (CommentSummary $summary): array => $summary->toArray(),
                $commentService->getSummariesForImage(
                    ImageId::from($image_id),
                    $only_validated_comments,
                    $params['comments_per_page'],
                    $params['comments_per_page'] * $params['comments_page']
                )
            );
        }

        $comment_post_data = null;
        if ($this->currentConfig->activateComments() and
            $is_commentable and
            (! $this->accessControl->isAGuest()
              or $this->currentConfig->commentsForall()
            )
        ) {
            $username = $this->currentUser->get()
                ->username->value ?? '';
            $comment_post_data['author'] = stripslashes($username);
            $comment_post_data['key'] = new EphemeralKeyService($this->currentConfig)->generate(2, (string) $params['image_id']);
        }

        $ret = $image_row;
        foreach (['id', 'width', 'height', 'hit', 'filesize'] as $k) {
            if (isset($ret[$k])) {
                assert(is_numeric($ret[$k]));
                $ret[$k] = (int) $ret[$k];
            }
        }
        foreach (['path', 'storage_category_id'] as $k) {
            unset($ret[$k]);
        }

        $ret['rates'] = [
            PwgResponseEncoder::ATTRIBUTES_KEY => $rating,
        ];
        $ret['categories'] = new PwgNamedArray(
            $related_categories,
            'category',
            ['id', 'url', 'page_url']
        );
        $ret['tags'] = new PwgNamedArray(
            $related_tags,
            'tag',
            $this->wsHelper->stdGetTagXmlAttributes()
        );
        if (isset($comment_post_data)) {
            $ret['comment_post'] = [
                PwgResponseEncoder::ATTRIBUTES_KEY => $comment_post_data,
            ];
        }
        $ret['comments_paging'] = new PwgNamedStruct(
            [
                'page' => $params['comments_page'],
                'per_page' => $params['comments_per_page'],
                'count' => count($related_comments),
                'total_count' => $nb_comments,
            ]
        );
        $ret['comments'] = new PwgNamedArray(
            $related_comments,
            'comment',
            ['id', 'date']
        );

        if ($service->_responseFormat !== 'rest') {
            return $ret; // for backward compatibility only
        } else {
            return [
                'image' => new PwgNamedStruct($ret, null, ['name', 'comment']),
            ];
        }
    }

    /**
     * API method
     * Rates an image
     * @param array{image_id: int, rate: float, ...} $params both mandatory
     *    (WsParamType::ID / WsParamType::FLOAT, no 'default') -- always plain scalars by
     *    the time this runs
     * @return PwgError|array<string, mixed> matches
     *   Rate\RateService::rate()'s own already-reviewed by-design shape
     */
    public function rate(array $params, PwgServer $service): PwgError|array
    {
        $accessible = $this->imageService->isImageAccessibleWithCondition(
            ImageId::from($params['image_id']),
            $this->permissionService->getPermissionCriteria()
        );
        if (! $accessible) {
            return new PwgError(404, 'Invalid image_id or access denied');
        }

        $res = $this->rateService
            ->rate($params['image_id'], (int) $params['rate']);

        if ($res === false) {
            $rate_items = $this->currentConfig->rateItems();
            return new PwgError(403, 'Forbidden or rate not in ' . implode(',', $rate_items));
        }
        return $res;
    }

    /**
     * API method
     * Returns a list of elements corresponding to a query search
     * @param array{query: string, per_page: int, page: int, order: string|null, f_min_rate: float|null, f_max_rate: float|null, f_min_hit: int|null, f_max_hit: int|null, f_min_ratio: float|null, f_max_ratio: float|null, f_max_level: int|null, f_min_date_available: string|null, f_max_date_available: string|null, f_min_date_created: string|null, f_max_date_created: string|null, ...} $params
     *    query: no WS_TYPE flag, mandatory -- always a plain string (see
     *    WsHelper::stdImageSqlFilterCriteria()'s docblock for the shared f_* filter set,
     *    merged in via ws.php's $f_params)
     * @return array{paging: PwgNamedStruct, images: PwgNamedArray}
     */
    public function search(array $params, PwgServer $service): array
    {
        $images = [];
        $filterCondition = $this->wsHelper->stdImageSqlFilterCriteria($params, $service)
            ->toSqlCondition('i.');
        $order_by = $this->wsHelper->stdImageSqlOrder($params, 'i.');

        $super_order_by = false;
        if ($order_by !== '') {
            // Communicates the effective order_by to SearchService::
            // getQuickSearchResults()/getRegularSearchResults() etc, which
            // read it back from $this->currentConfig-> for the rest of this request --
            // an in-memory-only override ($this->currentConfig->setOrderBy()), not a
            // DB write.
            $this->currentConfig->setOrderBy('ORDER BY ' . $order_by);
            $super_order_by = true; // quick_search_result might be faster
        }

        // SearchService::getQuickSearchResults()'s 'images_where' option
        // takes a single already-built SQL string with no bound-parameter
        // side-channel, so the filter condition is flattened back into
        // literal SQL here. Safe to do so: every one of
        // ImageFilterCriteria's own field values is already
        // is_numeric()/DateHelper::isValidMysqlDatetime()-validated (see
        // WsHelper::stdImageSqlFilterCriteria()'s own docblock) before ever
        // reaching $filterCondition, so no injection-capable character can
        // survive this substitution.
        $images_where = $filterCondition->sql;
        foreach ($filterCondition->parameters as $placeholder => $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $images_where = str_replace(':' . $placeholder, is_string($value) ? "'" . $value . "'" : (string) $value, $images_where);
        }

        $search_result = $this->searchService->getQuickSearchResults(
            $params['query'],
            [
                'super_order_by' => $super_order_by,
                'images_where' => $images_where,
            ]
        );

        // get_quick_search_results()'s return type is a generic array<string,
        // mixed> (cross-file root cause: include/functions_search.inc.php could
        // give 'items' a precise int[] shape, but that's shared by many other
        // callers -- narrow locally here instead).
        $search_items = $search_result['items'];
        if (! is_array($search_items)) {
            $search_items = [];
        }

        $image_ids = array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_slice(
                $search_items,
                $params['page'] * $params['per_page'],
                $params['per_page']
            )
        );

        if ((bool) count($image_ids)) {
            $image_ids = array_flip($image_ids);
            $favorite_ids = $this->urlService
                ->getUserFavorites();

            foreach ($this->imageRepository->findByIds(array_keys($image_ids)) as $imageRow) {
                // Unboxed here rather than kept as the typed object -- this
                // loop rebuilds a differently-shaped $image array from
                // $row's fields and separately passes the whole row to
                // WsHelper::stdGetUrls(array $image_row, ...), both of
                // which need real array semantics.
                $row = $imageRow->toArray();
                $image = [];
                $image['is_favorite'] = isset($favorite_ids[$imageRow->id->value]);
                foreach (['id', 'width', 'height', 'hit'] as $k) {
                    if (isset($row[$k])) {
                        $image[$k] = $row[$k];
                    }
                }
                foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                    $image[$k] = $row[$k];
                }

                $nameEvent2 = $this->eventDispatcher->dispatchChange(new RenderElementName(is_string($image['name']) ? $image['name'] : '', __FUNCTION__));
                $image['name'] = strip_tags($nameEvent2->elementName);
                $descriptionEvent2 = $this->eventDispatcher->dispatchChange(new RenderElementDescription(is_string($image['comment']) ? $image['comment'] : '', __FUNCTION__));
                $image['comment'] = $descriptionEvent2->elementDescription;

                $image = array_merge($image, $this->wsHelper->stdGetUrls($row, $this->urlService));
                $images[$image_ids[$image['id']]] = $image;
            }
            ksort($images, SORT_NUMERIC);
            $images = array_values($images);
        }

        return [
            'paging' => new PwgNamedStruct(
                [
                    'page' => $params['page'],
                    'per_page' => $params['per_page'],
                    'count' => count($images),
                    'total_count' => count($search_items),
                ]
            ),
            'images' => new PwgNamedArray(
                $images,
                'image',
                $this->wsHelper->stdGetImageXmlAttributes()
            ),
        ];
    }

    /**
     * API method
     * Registers a new search
     *
     * Every param here is WsParamFlag::OPTIONAL with no 'default' key, so
     * PwgServer::invoke() leaves any not provided by the caller entirely
     * absent from $params (not filled with null) -- hence the optional (?:)
     * shape keys throughout. FORCE_ARRAY params, when present, are always
     * arrays (never a bare scalar).
     *
     * @param array{search_id?: string, allwords?: string, allwords_mode?: string, allwords_fields?: array<int, string>, tags?: array<int, int>, tags_mode?: string, categories?: array<int, int>, categories_withsubs?: bool, authors?: array<int, string>, added_by?: array<int, int>, filetypes?: array<int, string>, date_posted_preset?: string, date_posted_custom?: array<int, string>, date_created_preset?: string, date_created_custom?: array<int, string>, ratios?: array<int, string>, ratings?: array<int, string>, filesize_min?: int, filesize_max?: int, height_min?: int, height_max?: int, width_min?: int, width_max?: int, ...} $params
     * @return PwgError|array{search_id: string, search_url: string}
     */
    public function filteredSearchCreate(array $params, PwgServer $service): PwgError|array
    {

        $searchService = $this->searchService;

        // * check the search exists
        $search_info = null;
        if (isset($params['search_id'])) {
            if (in_array(SearchService::getSearchIdPattern($params['search_id']), [null, ''], true)) {
                return new PwgError(WsError::INVALID_PARAM, 'Invalid search_id input parameter.');
            }

            $search_info = $searchService->getValidatedSearchInfo($params['search_id'], null);
            if ($search_info === null) {
                return new PwgError(WsError::INVALID_PARAM, 'This search does not exist.');
            }
        }

        // 'fields' (and its 'date_posted'/'date_created' sub-arrays) are
        // predeclared so PHPStan can track $search's shape as it's built up
        // below -- this changes nothing at runtime (PHP would auto-vivify the
        // same nested arrays via the assignments further down anyway).
        $search = [
            'mode' => 'AND',
            'fields' => [
                'date_posted' => [],
                'date_created' => [],
            ],
        ];

        // * check all parameters
        if (isset($params['allwords'])) {
            $search['fields']['allwords'] = [];

            if (! isset($params['allwords_mode'])) {
                $params['allwords_mode'] = 'AND';
            }
            if (! (bool) preg_match('/^(OR|AND)$/', $params['allwords_mode'])) {
                return new PwgError(WsError::INVALID_PARAM, 'Invalid parameter allwords_mode');
            }
            $search['fields']['allwords']['mode'] = $params['allwords_mode'];

            $allwords_fields_available = ['name', 'comment', 'file', 'author', 'tags', 'cat-title', 'cat-desc'];
            if (! isset($params['allwords_fields'])) {
                $params['allwords_fields'] = $allwords_fields_available;
            }
            foreach ($params['allwords_fields'] as $field) {
                if (! in_array($field, $allwords_fields_available, true)) {
                    return new PwgError(WsError::INVALID_PARAM, 'Invalid parameter allwords_fields');
                }
            }
            $search['fields']['allwords']['fields'] = $params['allwords_fields'];

            $search['fields']['allwords']['words'] = SearchService::splitAllwords($params['allwords']);
        }

        if (isset($params['tags'])) {
            foreach ($params['tags'] as $tag_id) {
                if (! (bool) preg_match('/^\d+$/', (string) $tag_id)) {
                    return new PwgError(WsError::INVALID_PARAM, 'Invalid parameter tags');
                }
            }

            if (! isset($params['tags_mode'])) {
                $params['tags_mode'] = 'AND';
            }
            if (! (bool) preg_match('/^(OR|AND)$/', $params['tags_mode'])) {
                return new PwgError(WsError::INVALID_PARAM, 'Invalid parameter tags_mode');
            }

            $search['fields']['tags'] = [
                'words' => $params['tags'],
                'mode' => $params['tags_mode'],
            ];
        }

        if (isset($params['categories'])) {
            foreach ($params['categories'] as $cat_id) {
                if (! (bool) preg_match('/^\d+$/', (string) $cat_id)) {
                    return new PwgError(WsError::INVALID_PARAM, 'Invalid parameter categories');
                }
            }

            $search['fields']['cat'] = [
                'words' => $params['categories'],
                'sub_inc' => $params['categories_withsubs'] ?? false,
            ];
        }

        if (isset($params['authors'])) {
            $authors = [];

            foreach ($params['authors'] as $author) {
                $authors[] = strip_tags($author);
            }

            $search['fields']['author'] = [
                'words' => $authors,
                'mode' => 'OR',
            ];
        }

        if (isset($params['filetypes'])) {
            foreach ($params['filetypes'] as $ext) {
                if (! (bool) preg_match('/^[a-z0-9]+$/i', $ext)) {
                    return new PwgError(WsError::INVALID_PARAM, 'Invalid parameter filetypes');
                }
            }

            $search['fields']['filetypes'] = $params['filetypes'];
        }

        if (isset($params['added_by'])) {
            foreach ($params['added_by'] as $user_id) {
                if (! (bool) preg_match('/^\d+$/', (string) $user_id)) {
                    return new PwgError(WsError::INVALID_PARAM, 'Invalid parameter added_by');
                }
            }

            $search['fields']['added_by'] = $params['added_by'];
        }

        if (isset($params['date_posted_preset'])) {
            if (! (bool) preg_match('/^(24h|7d|30d|3m|6m|custom|)$/', $params['date_posted_preset'])) {
                return new PwgError(WsError::INVALID_PARAM, 'Invalid parameter date_posted_preset');
            }

            @$search['fields']['date_posted']['preset'] = $params['date_posted_preset'];

            if ($search['fields']['date_posted']['preset'] === 'custom' and (! isset($params['date_posted_custom']) or $params['date_posted_custom'] === [])) {
                return new PwgError(WsError::INVALID_PARAM, 'date_posted_custom is missing');
            }
        }

        if (isset($params['date_posted_custom'])) {
            if (! isset($search['fields']['date_posted']['preset']) or $search['fields']['date_posted']['preset'] !== 'custom') {
                return new PwgError(WsError::INVALID_PARAM, 'date_posted_custom provided date_posted_preset is not custom');
            }

            foreach ($params['date_posted_custom'] as $date) {
                $correct_format = false;

                $ymd = substr($date, 0, 1);
                if ($ymd === 'y') {
                    if ((bool) preg_match('/^y(\d{4})$/', $date, $matches)) {
                        $correct_format = true;
                    }
                } elseif ($ymd === 'm') {
                    if ((bool) preg_match('/^m(\d{4}-\d{2})$/', $date, $matches)) {
                        [$year, $month] = explode('-', $matches[1]);
                        if ($month >= 1 and $month <= 12) {
                            $correct_format = true;
                        }
                    }
                } elseif ($ymd === 'd') {
                    if ((bool) preg_match('/^d(\d{4}-\d{2}-\d{2})$/', $date, $matches)) {
                        [$year, $month, $day] = explode('-', $matches[1]);
                        if ($month >= 1 and $month <= 12 and $day >= 1 and $day <= cal_days_in_month(CAL_GREGORIAN, (int) $month, (int) $year)) {
                            $correct_format = true;
                        }
                    }
                }

                if (! $correct_format) {
                    return new PwgError(WsError::INVALID_PARAM, 'date_posted_custom, invalid option ' . $date);
                }

                @$search['fields']['date_posted']['custom'][] = $date;
            }
        }

        if (isset($params['date_created_preset'])) {
            if (! (bool) preg_match('/^(7d|30d|3m|6m|12m|custom|)$/', $params['date_created_preset'])) {
                return new PwgError(WsError::INVALID_PARAM, 'Invalid parameter date_created_preset');
            }

            @$search['fields']['date_created']['preset'] = $params['date_created_preset'];

            if ($search['fields']['date_created']['preset'] === 'custom' and (! isset($params['date_created_custom']) or $params['date_created_custom'] === [])) {
                return new PwgError(WsError::INVALID_PARAM, 'date_created_custom is missing');
            }
        }

        if (isset($params['date_created_custom'])) {
            if (! isset($search['fields']['date_created']['preset']) or $search['fields']['date_created']['preset'] !== 'custom') {
                return new PwgError(WsError::INVALID_PARAM, 'date_created_custom provided date_created_preset is not custom');
            }

            foreach ($params['date_created_custom'] as $date) {
                $correct_format = false;

                $ymd = substr($date, 0, 1);
                if ($ymd === 'y') {
                    if ((bool) preg_match('/^y(\d{4})$/', $date, $matches)) {
                        $correct_format = true;
                    }
                } elseif ($ymd === 'm') {
                    if ((bool) preg_match('/^m(\d{4}-\d{2})$/', $date, $matches)) {
                        [$year, $month] = explode('-', $matches[1]);
                        if ($month >= 1 and $month <= 12) {
                            $correct_format = true;
                        }
                    }
                } elseif ($ymd === 'd') {
                    if ((bool) preg_match('/^d(\d{4}-\d{2}-\d{2})$/', $date, $matches)) {
                        [$year, $month, $day] = explode('-', $matches[1]);
                        if ($month >= 1 and $month <= 12 and $day >= 1 and $day <= cal_days_in_month(CAL_GREGORIAN, (int) $month, (int) $year)) {
                            $correct_format = true;
                        }
                    }
                }

                if (! $correct_format) {
                    return new PwgError(WsError::INVALID_PARAM, 'date_created_custom, invalid option ' . $date);
                }

                @$search['fields']['date_created']['custom'][] = $date;
            }
        }

        if (isset($params['ratios'])) {
            foreach ($params['ratios'] as $ext) {
                if (! (bool) preg_match('/^[a-z0-9]+$/i', $ext)) {
                    return new PwgError(WsError::INVALID_PARAM, 'Invalid parameter ratios');
                }
            }

            $search['fields']['ratios'] = $params['ratios'];
        }

        if (isset($params['expert'])) {
            $search['fields']['expert'] = [
                'string' => $params['expert'],
            ];
        }

        if ($this->currentConfig->rateEnabled() and isset($params['ratings'])) {
            $search['fields']['ratings'] = $params['ratings'];
        }

        if (isset($params['filesize_min'])) {
            $search['fields']['filesize_min'] = $params['filesize_min'];
        }

        if (isset($params['filesize_max'])) {
            $search['fields']['filesize_max'] = $params['filesize_max'];
        }

        if (isset($params['width_min'])) {
            $search['fields']['width_min'] = $params['width_min'];
        }

        if (isset($params['width_max'])) {
            $search['fields']['width_max'] = $params['width_max'];
        }

        if (isset($params['height_min'])) {
            $search['fields']['height_min'] = $params['height_min'];
        }

        if (isset($params['height_max'])) {
            $search['fields']['height_max'] = $params['height_max'];
        }

        $forked_from = $search_info?->id;
        [$search_uuid, $search_url] = $searchService->saveSearch($search, $this->urlService, $forked_from);

        return [
            'search_id' => $search_uuid,
            'search_url' => $search_url,
        ];
    }

    /**
     * API method
     * Sets the level of an image
     * @param array{image_id: array<int, int>, level: int, ...} $params
     *    image_id: WsParamFlag::FORCE_ARRAY|WsParamType::ID -- always coerced to a list
     *      of positive ints by PwgServer::invoke() before this runs
     *    level: WsParamType::INT|WsParamType::POSITIVE, mandatory (no 'default') -- always
     *      a plain int by the time this runs
     */
    public function setPrivacyLevel(array $params, PwgServer $service): PwgError|int
    {

        $available_permission_levels = $this->currentConfig->availablePermissionLevels();

        if (! in_array($params['level'], $available_permission_levels, true)) {
            return new PwgError(WsError::INVALID_PARAM, 'Invalid level');
        }

        $affected_rows = $this->imageService->updateLevelForImages($params['image_id'], $params['level']);
        $this->entityManager->clear();

        $this->activityService->record('photo', $params['image_id'], 'edit');

        if ($affected_rows > 0) {
            PermissionCacheInvalidator::invalidate();
        }
        return $affected_rows;
    }

    /**
     * API method
     * Sets the rank of an image in a category
     * @param array{image_id: array<int, int>, category_id: int, rank: int|null, ...} $params
     *    image_id: WsParamFlag::FORCE_ARRAY|WsParamType::ID -- always a list of positive
     *    ints. category_id: WsParamType::ID, mandatory. rank: WsParamType::INT|POSITIVE|
     *    NOTNULL with a null default -- int when the caller provides it, null
     *    otherwise
     * @return PwgError|array{image_id: list<int|string>, category_id: int}|array{image_id: int, category_id: int, rank: int}
     *   the 2 real return sites have genuinely different shapes -- the
     *   multi-image branch above returns the reordered id list (no rank),
     *   the single-image branch below returns the one image_id plus its
     *   new rank
     */
    public function setRank(array $params, PwgServer $service): array|PwgError
    {
        if (count($params['image_id']) > 1) {
            $this->imageService
                ->saveImagesOrder(
                    $params['category_id'],
                    $params['image_id']
                );

            $image_ids = $this->imageService->getImageIdsOrderedByRankForCategory(CategoryId::from($params['category_id']));

            // return data for client
            return [
                'image_id' => $image_ids,
                'category_id' => $params['category_id'],
            ];
        }

        // turns image_id into a simple int instead of array
        $params['image_id'] = array_shift($params['image_id']);

        if ($params['image_id'] === null) {
            return new PwgError(WsError::MISSING_PARAM, 'image_id is missing');
        }

        if ($params['rank'] === null || $params['rank'] === 0) {
            return new PwgError(WsError::MISSING_PARAM, 'rank is missing');
        }

        $imageId = ImageId::from($params['image_id']);
        $categoryId = CategoryId::from($params['category_id']);

        // does the image really exist?
        if (! $this->imageService->existsById($imageId)) {
            return new PwgError(404, 'image_id not found');
        }

        // is the image associated to this category?
        if (! $this->imageService->isImageInCategory($imageId, $categoryId)) {
            return new PwgError(404, 'This image is not associated to this category');
        }

        // what is the current higher rank for this category?
        $max_rank = $this->imageService->getMaxRankForCategory($categoryId);

        if ($max_rank !== null) {
            if ($params['rank'] > $max_rank) {
                $params['rank'] = $max_rank + 1;
            }
        } else {
            $params['rank'] = 1;
        }

        // update rank for all other photos in the same category
        $this->imageService->incrementRanksFromForCategory($categoryId, $params['rank']);

        // set the new rank for the photo
        $this->imageService->updateRankForImageInCategory($imageId, $categoryId, $params['rank']);

        // return data for client
        return [
            'image_id' => $params['image_id'],
            'category_id' => $params['category_id'],
            'rank' => $params['rank'],
        ];
    }

    /**
     * API method
     * Adds a file chunk
     * @param array{data: string, original_sum: string, type: string, position: string, ...} $params
     *    none of these have a WS_TYPE flag; data/original_sum/position are
     *    mandatory (no 'default'), type defaults to 'file' -- all always plain
     *    strings (see PwgServer::invoke()'s array-rejection check)
     */
    public function addChunk(array $params, PwgServer $service): ?PwgError
    {
        $logger = $this->currentLogger->get();

        foreach ($params as $param_key => $param_value) {
            if ($param_key === 'data') {
                continue;
            }

            $logger->debug(sprintf(
                '[ws_images_add_chunk] input param "%s" : "%s"',
                $param_key,
                is_scalar($param_value) ? (string) $param_value : 'NULL'
            ), 'WS');
        }

        $upload_dir_conf = $this->paths->root . $this->currentConfig->uploadDir();
        $upload_dir = $upload_dir_conf . '/buffer';

        // create the upload directory tree if not exists
        if (! FilesystemHelper::mkgetdir($upload_dir, $this->currentConfig, FilesystemHelper::MKGETDIR_DEFAULT & ~FilesystemHelper::MKGETDIR_DIE_ON_ERROR)) {
            return new PwgError(500, 'error during buffer directory creation');
        }

        $filename = sprintf(
            '%s-%s-%05u.block',
            $params['original_sum'],
            $params['type'],
            (int) $params['position']
        );

        $logger->debug('[ws_images_add_chunk] data length : ' . strlen($params['data']), 'WS');

        $decoded_data = base64_decode($params['data'], true);
        $bytes_written = $decoded_data === false ? false : file_put_contents(
            $upload_dir . '/' . $filename,
            $decoded_data
        );

        if ($bytes_written === false) {
            return new PwgError(
                500,
                'an error has occured while writting chunk ' . $params['position'] . ' for ' . $params['type']
            );
        }

        return null;
    }

    /**
     * API method
     * @param array{image_id: int, type: string, sum: string, ...} $params
     *    image_id: WsParamType::ID, mandatory. type: no WS_TYPE flag, defaults to
     *    'file'. sum: no WS_TYPE flag, mandatory -- both always plain strings
     */
    public function addFile(array $params, PwgServer $service): PwgError|bool|null
    {
        $logger = $this->currentLogger->get();

        $logger->debug(__FUNCTION__, 'WS', $params);

        // what is the path and other infos about the photo?
        $image = $this->imageService->getUploadInfoById(ImageId::from($params['image_id']));
        if ($image === null) {
            return new PwgError(404, 'image_id not found');
        }

        // this legacy chunked-upload flow locates buffered chunks by md5sum, so
        // it cannot proceed for a photo that has none (e.g. added before the
        // md5sum feature was enabled, see pwg.images.setMd5sum).
        if (! is_string($image->md5sum)) {
            return new PwgError(500, '[ws_images_addFile] image_id ' . $params['image_id'] . ' has no md5sum');
        }

        // since Piwigo 2.4 and derivatives, we do not take the imported "thumb" into account
        if ($params['type'] === 'thumb') {
            $this->removeChunks($image->md5sum, $params['type']);
            return true;
        }

        // since Piwigo 2.4 and derivatives, we only care about the "original"
        $original_type = 'file';
        if ($params['type'] === 'high') {
            $original_type = 'high';
        }

        $upload_dir_conf = $this->paths->root . $this->currentConfig->uploadDir();
        $file_path = $upload_dir_conf . '/buffer/' . $image->md5sum . '-original';

        $this->mergeChunks($file_path, $image->md5sum, $original_type);
        chmod($file_path, 0644);

        // if we receive the "file", we only update the original if the "file" is
        // bigger than current original
        if ($params['type'] === 'file') {
            $do_update = false;

            $infos = $this->uploadService
                ->pwgImageInfos($file_path);

            $imageArr = $image->toArray();
            if ($infos->width > $imageArr['width']
                || $infos->height > $imageArr['height']
                || $infos->filesize > $imageArr['filesize']) {
                $do_update = true;
            }

            if (! $do_update) {
                unlink($file_path);
                return true;
            }
        }

        $image_id = $this->uploadService
            ->addUploadedFile(
                $file_path,
                $this->urlService,
                $image->file,
                null,
                null,
                $params['image_id'],
                $image->md5sum, // we force the md5sum to remain the same
                $service
            );

        return null;
    }

    /**
     * API method
     * Adds an image
     * @param array{thumbnail_sum: string|null, high_sum: string|null, original_sum: string, original_filename: string|null, name: string|null, author: string|null, date_creation: string|null, comment: string|null, categories: string|null, tag_ids: string|null, level: int, check_uniqueness: bool, image_id: int|null, ...} $params
     *    All except level/check_uniqueness/image_id have no WS_TYPE flag and a
     *    null default (or none, for the mandatory original_sum) -- always
     *    plain strings when present (see PwgServer::invoke()'s array-rejection
     *    check). level: WsParamType::INT|POSITIVE, default 0 (non-null) -- always
     *    int. check_uniqueness: WsParamType::BOOL, default true -- always bool.
     *    image_id: WsParamType::ID, null default -- int|null.
     * @return PwgError|array{image_id: int|string, url: string}
     */
    public function add(array $params, PwgServer $service): PwgError|array
    {
        $logger = $this->currentLogger->get();

        foreach ($params as $param_key => $param_value) {
            $logger->debug(sprintf(
                '[pwg.images.add] input param "%s" : "%s"',
                $param_key,
                is_scalar($param_value) ? (string) $param_value : 'NULL'
            ), 'WS');
        }

        if ($params['image_id'] > 0) {
            if (! $this->imageService->existsById(ImageId::from($params['image_id']))) {
                return new PwgError(404, 'image_id not found');
            }
        }

        // does the image already exists ?
        // $params['original_sum']/original_filename are bound as query
        // parameters via existsWithColumnValue(), not spliced into SQL.
        //
        // Neither piwigo_images.md5sum nor .file is indexed, and the
        // check-then-insert sequence below has a time-of-check-to-time-of-use
        // race (two concurrent uploads of the same value could both pass
        // this check before either INSERT completes). A MySQL advisory
        // lock, scoped to the specific column/value being uploaded and held
        // from this check through addUploadedFile()'s completion, closes
        // the race without affecting the check_uniqueness=false path (the
        // lock is only ever acquired inside this same `if`, so a caller
        // that opts out of the uniqueness check never touches it).
        $uniqueness_lock_conn = null;
        $uniqueness_lock_name = null;

        if ($params['check_uniqueness']) {
            $uniqueness_column = match ($this->currentConfig->uniquenessMode()) {
                'md5sum' => 'md5sum',
                'filename' => 'file',
                default => null, // no known uniqueness_mode: skip the uniqueness check
            };

            if ($uniqueness_column !== null) {
                $uniqueness_value = $uniqueness_column === 'md5sum' ? $params['original_sum'] : ($params['original_filename'] ?? '');

                $uniqueness_lock_conn = DbConnection::build();
                // GET_LOCK() names are capped at 64 characters -- $uniqueness_value
                // is a caller-supplied filename in the 'file' uniqueness mode (up to
                // piwigo_images.file's own 255-char width), so it's hashed rather
                // than concatenated literally. $this->dbCredentials->prefix is
                // folded into the hashed input (not just a literal prefix) so it
                // still contributes to collision-avoidance against unrelated
                // applications on a shared MySQL server.
                $uniqueness_lock_name = 'piwigo_iu_' . sha1($this->dbCredentials->prefix . ':' . $uniqueness_column . ':' . $uniqueness_value);
                $uniqueness_lock_ok = AdvisorySessionLock::acquire(
                    $uniqueness_lock_conn,
                    $uniqueness_lock_name,
                    self::UPLOAD_UNIQUENESS_LOCK_TIMEOUT_SECONDS
                );

                // A failed/timed-out acquisition means another request is right
                // now checking or inserting this exact value -- treated the same
                // as "file already exists" rather than silently proceeding
                // unprotected, since that concurrent request is the same
                // condition this check exists to catch.
                if (! $uniqueness_lock_ok || $this->imageService->existsWithColumnValue(
                    $uniqueness_column === 'md5sum' ? ImageUniquenessColumn::Md5sum : ImageUniquenessColumn::File,
                    $uniqueness_value
                )) {
                    if ($uniqueness_lock_ok) {
                        AdvisorySessionLock::release($uniqueness_lock_conn, $uniqueness_lock_name);
                    }
                    return new PwgError(500, 'file already exists');
                }
            }
        }

        // due to the new feature "derivatives" (multiple sizes) introduced for
        // Piwigo 2.4, we only take the biggest photos sent on
        // pwg.images.addChunk. If "high" is available we use it as "original"
        // else we use "file".
        $this->removeChunks($params['original_sum'], 'thumb');

        if (isset($params['high_sum'])) {
            $original_type = 'high';
            $this->removeChunks($params['original_sum'], 'file');
        } else {
            $original_type = 'file';
        }

        $upload_dir_conf = $this->paths->root . $this->currentConfig->uploadDir();
        $file_path = $upload_dir_conf . '/buffer/' . $params['original_sum'] . '-original';

        $this->mergeChunks($file_path, $params['original_sum'], $original_type);
        chmod($file_path, 0644);

        try {
            $image_id = $this->uploadService
                ->addUploadedFile(
                    $file_path,
                    $this->urlService,
                    $params['original_filename'],
                    null, // categories
                    $params['level'],
                    $params['image_id'] > 0 ? $params['image_id'] : null,
                    $params['original_sum'],
                    $service
                );
        } finally {
            // $uniqueness_lock_name is always assigned in the same branch as
            // $uniqueness_lock_conn (see above), so checking the connection
            // alone is sufficient -- PHPStan proves this itself, flagging a
            // separate null-check on the name as redundant.
            if ($uniqueness_lock_conn !== null) {
                AdvisorySessionLock::release($uniqueness_lock_conn, $uniqueness_lock_name);
            }
        }

        $info_columns = [
            'name',
            'author',
            'comment',
            'date_creation',
        ];

        $this->imageService->updateDescriptiveFields(
            ImageId::from($image_id),
            name: is_string($params['name']) ? $params['name'] : null,
            author: is_string($params['author']) ? $params['author'] : null,
            comment: is_string($params['comment']) ? $params['comment'] : null,
            dateCreation: is_string($params['date_creation']) ? $params['date_creation'] : null,
        );

        $url_params = [
            'image_id' => $image_id,
        ];

        // let's add links between the image and the categories
        if (isset($params['categories'])) {
            $this->addImageCategoryRelations(ImageId::from($image_id), $params['categories']);

            if ((bool) preg_match('/^\d+/', $params['categories'], $matches)) {
                $category_id = $matches[0];

                $category = $this->categoryService->getIdNamePermalinkById((int) $category_id);

                $url_params['section'] = 'categories';
                $url_params['category'] = $category;
            }
        }

        // and now, let's create tag associations
        if (isset($params['tag_ids']) and $params['tag_ids'] !== '') {
            $this->tagService
                ->setTags(
                    array_values(array_filter(array_map(TagId::tryFrom(...), explode(',', $params['tag_ids'])))),
                    $image_id
                );
        }

        PermissionCacheInvalidator::invalidate();

        return [
            'image_id' => $image_id,
            'url' => $this->urlService
                ->makePictureUrl($url_params),
        ];
    }

    /**
     * API method
     * Adds a image (simple way)
     * @param array{category: array<int, int>, name: string|null, author: string|null, comment: string|null, level: int, tags: string|array<array-key, string>|null, image_id: int|null, ...} $params
     *    category: WsParamFlag::FORCE_ARRAY|WsParamType::ID with a null default --
     *    makeArrayParam() converts the null default to [], never null,
     *    always a list of positive ints. name/author/comment: no WS_TYPE
     *    flag, null default -- string|null. level: WsParamType::INT|POSITIVE,
     *    default 0 (non-null) -- always int. tags: WsParamFlag::ACCEPT_ARRAY (not
     *    FORCE), no WS_TYPE flag, null default -- string, array (if the
     *    caller uses bracket syntax), or null. image_id: WsParamType::ID, null
     *    default -- int|null.
     * @return PwgError|array{image_id: int|string, url: string}
     */
    public function addSimple(array $params, PwgServer $service): PwgError|array
    {
        $logger = $this->currentLogger->get();

        $uploaded_image = UploadedFileRequest::fromFilesKey('image');
        if (! $uploaded_image->present) {
            return new PwgError(405, 'The image (file) is missing');
        }

        if ($uploaded_image->error !== null && $uploaded_image->error !== 0) {
            $upload_error = $uploaded_image->error;
            $message = match ($upload_error) {
                UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
                UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload. ' .
                'PHP does not provide a way to ascertain which extension caused the file ' .
                'upload to stop; examining the list of loaded extensions with phpinfo() may help.',
                default => 'Error number ' . $upload_error . ' occurred while uploading a file.',
            };

            $logger->error(__FUNCTION__ . ' ' . $message);
            return new PwgError(500, $message);
        }

        if ($params['image_id'] > 0) {
            if (! $this->imageService->existsById(ImageId::from($params['image_id']))) {
                return new PwgError(404, 'image_id not found');
            }
        }

        $uploaded_tmp_name = $uploaded_image->tmpName;
        if ($uploaded_tmp_name === null) {
            return new PwgError(500, '[ws_images_addSimple] missing uploaded file temp name');
        }

        $image_id = $this->uploadService
            ->addUploadedFile(
                $uploaded_tmp_name,
                $this->urlService,
                $uploaded_image->name,
                $params['category'],
                8,
                $params['image_id'] > 0 ? $params['image_id'] : null,
                null,
                $service
            );

        $this->imageService->updateLevelForImages([$image_id], $params['level']);

        $this->imageService->updateDescriptiveFields(
            ImageId::from($image_id),
            name: is_string($params['name']) ? $params['name'] : null,
            author: is_string($params['author']) ? $params['author'] : null,
            comment: is_string($params['comment']) ? $params['comment'] : null,
            dateCreation: is_string($params['date_creation'] ?? null) ? $params['date_creation'] : null,
        );
        $this->entityManager->clear();

        if (isset($params['tags']) and $params['tags'] !== '' and $params['tags'] !== []) {
            $tagService = $this->tagService;

            $tag_ids = [];
            if (is_array($params['tags'])) {
                foreach ($params['tags'] as $tag_name) {
                    $tag_ids[] = $tagService->tagIdFromTagName($tag_name);
                }
            } else {
                $tag_names = preg_split('~(?<!\\\),~', $params['tags']);
                if ($tag_names === false) {
                    throw new Exception('ws_images_addSimple(): preg_split() failed');
                }
                foreach ($tag_names as $tag_name) {
                    $unescaped_tag_name = preg_replace('#\\\\*,#', ',', $tag_name);
                    assert($unescaped_tag_name !== null);
                    $tag_ids[] = $tagService->tagIdFromTagName($unescaped_tag_name);
                }
            }

            $tagService->addTags($tag_ids, [$image_id]);
        }

        $url_params = [
            'image_id' => $image_id,
        ];

        if ($params['category'] !== []) {
            $category = $this->categoryService->getIdNamePermalinkById($params['category'][0]);

            $url_params['section'] = 'categories';
            $url_params['category'] = $category;
        }

        // update metadata from the uploaded file (exif/iptc), even if the sync
        // was already performed by add_uploaded_file().
        $this->metadataService
            ->syncMetadata([$image_id]);

        return [
            'image_id' => $image_id,
            'url' => $this->urlService
                ->makePictureUrl($url_params),
        ];
    }

    /**
     * API method
     * Uploads a file, chunked or whole
     *
     * @param array{name: string|null, category: array<int, int>, level: int, format_of: int|null, update_mode: bool, pwg_token: string, ...} $params
     *    name: no WS_TYPE flag, null default -- string|null. category:
     *    WsParamFlag::FORCE_ARRAY|WsParamType::ID, null default -- makeArrayParam()
     *    converts the null default to [], so never null. level:
     *    WsParamType::INT|POSITIVE, default 0 (non-null) -- always int. format_of:
     *    WsParamType::ID, null default -- int|null. update_mode: WsParamType::BOOL,
     *    default false (non-null) -- always bool. pwg_token: no WS_TYPE flag,
     *    mandatory -- always a plain string.
     * The 2 real array-literal return sites have genuinely different shapes
     * (a format_of upload returns image_id/src/square_src/name/add_status;
     * a new-photo upload adds a 'category' sub-array on top) -- left as
     * array<string, mixed> rather than an unverified 2-branch union.
     * @return PwgError|array<string, mixed>|null
     */
    public function upload(array $params, PwgServer $service): PwgError|array|null
    {
        $format_ext = null;

        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (isset($params['format_of'])) {
            // are formats enabled?
            if (! $this->currentConfig->isFormatsEnabled()) {
                return new PwgError(401, 'formats are disabled');
            }

            $format_ext_list = $this->currentConfig->formatExtensions();

            // We must check if the extension is in the authorized list.
            if ((bool) preg_match('/\.(' . implode('|', $format_ext_list) . ')$/', (string) $params['name'], $matches)) {
                $format_ext = $matches[1];
            }

            if (! is_string($format_ext) || $format_ext === '') {
                return new PwgError(401, 'unexpected format extension of file "' . $params['name'] . '" (authorized extensions: ' . implode(', ', $format_ext_list) . ')');
            }
        }

        $upload_dir_conf = $this->paths->root . $this->currentConfig->uploadDir();
        $upload_dir = $upload_dir_conf . '/buffer';

        // create the upload directory tree if not exists
        if (! FilesystemHelper::mkgetdir($upload_dir, $this->currentConfig, FilesystemHelper::MKGETDIR_DEFAULT & ~FilesystemHelper::MKGETDIR_DIE_ON_ERROR)) {
            return new PwgError(500, 'error during buffer directory creation');
        }

        $chunkedUploadRequest = ChunkedUploadRequest::fromGlobals();
        $uploaded_file = UploadedFileRequest::fromFilesKey('file');

        // Get a file name
        if ($chunkedUploadRequest->requestNamePresent) {
            $fileName = $chunkedUploadRequest->requestName;
        } elseif ($uploaded_file->present) {
            $fileName = $uploaded_file->name;
        } else {
            $fileName = uniqid('file_');
        }

        // change the name of the file in the buffer to avoid any unexpected
        // extension. Function add_uploaded_file will eventually clean the mess.
        $fileName = md5(is_string($fileName) ? $fileName : '');

        $filePath = $upload_dir . DIRECTORY_SEPARATOR . $fileName;

        // Chunking might be enabled
        $chunk = $chunkedUploadRequest->chunk;
        $chunks = $chunkedUploadRequest->chunks;

        // Open temp file
        if (! (bool) ($out = @fopen("{$filePath}.part", ((bool) $chunks) ? 'ab' : 'wb'))) {
            return new PwgError(102, 'Failed to open output stream.');
        }

        // $_FILES having ANY entry at all (even one not named 'file')
        // already commits to the "move an uploaded file" path below,
        // rather than silently falling through to the php://input branch
        // -- a minimal, single-fact existence check, same shape as
        // Ws\PwgServer::isPost()'s own raw $_POST read.
        if ($_FILES !== []) {
            if (! $uploaded_file->present) {
                return new PwgError(103, 'Failed to move uploaded file.');
            }
            $uploaded_file_tmp_name = $uploaded_file->tmpName;

            if (($uploaded_file->error !== null && $uploaded_file->error !== 0) || $uploaded_file_tmp_name === null || ! is_uploaded_file($uploaded_file_tmp_name)) {
                return new PwgError(103, 'Failed to move uploaded file.');
            }

            // Read binary input stream and append it to temp file
            if (! (bool) ($in = @fopen($uploaded_file_tmp_name, 'rb'))) {
                return new PwgError(101, 'Failed to open input stream.');
            }
        } else {
            if (! (bool) ($in = @fopen('php://input', 'rb'))) {
                return new PwgError(101, 'Failed to open input stream.');
            }
        }

        while ((bool) ($buff = fread($in, 4096))) {
            fwrite($out, $buff);
        }

        @fclose($out);
        @fclose($in);

        $add_status = 'add';
        // Check if file has been uploaded
        if (! (bool) $chunks || $chunk === $chunks - 1) {
            // Strip the temp .part suffix off
            rename("{$filePath}.part", $filePath);

            if (isset($params['format_of'])) {
                $formatOfId = ImageId::tryFrom($params['format_of']);
                $imageRow = $formatOfId === null ? null : $this->imageRepository->findById($formatOfId);
                if ($imageRow === null) {
                    return new PwgError(404, __FUNCTION__ . ' : image_id not found');
                }
                $image = $imageRow->toArray();

                $add_status = $this->uploadService
                    ->addFormat($filePath, $format_ext, $imageRow->id->value);

                return [
                    'image_id' => $image['id'],
                    'src' => DerivativeImage::thumb_url($image),
                    'square_src' => DerivativeImage::url($this->imageStdParams->get_by_type(ImageStdParams::SQUARE), $image),
                    'name' => $image['name'],
                    'add_status' => $add_status,
                ];
            }

            $name = stripslashes((string) $params['name']);
            $id_image = null; // null by default

            if ($params['update_mode']) {
                $existing_ids = $this->imageService->getIdsByFilenameInCategory($name, CategoryId::from($params['category'][0]));
                if ($existing_ids !== []) {
                    $id_image = $existing_ids[0]; // take the id of the already existing image to replace it
                    $add_status = 'update';
                }
            }

            $image_id = $this->uploadService
                ->addUploadedFile(
                    $filePath,
                    $this->urlService,
                    $name, // function add_uploaded_file will secure before insert
                    $params['category'],
                    $params['level'],
                    $id_image,
                    null,
                    $service
                );

            $image_infos = $this->imageService->getUploadResultInfoById(ImageId::from($image_id));
            if ($image_infos === null) {
                throw new Exception('ws_images_upload(): image fetch failed right after inserting it');
            }

            $categoryId = CategoryId::from($params['category'][0]);
            $nb_photos_in_category = $this->imageService->countImagesInCategory($categoryId);
            $nb_photos_lounge = $this->imageService->countLoungeImagesPendingForCategory($categoryId);

            $category_name = $this->htmlService
                ->getCatDisplayNameFromId($params['category'][0], null);

            return [
                'image_id' => $image_id,
                'src' => DerivativeImage::thumb_url($image_infos->toArray()),
                'square_src' => DerivativeImage::url($this->imageStdParams->get_by_type(ImageStdParams::SQUARE), $image_infos->toArray()),
                'name' => $image_infos->name,
                'category' => [
                    'id' => $params['category'][0],
                    'nb_photos' => $nb_photos_in_category + $nb_photos_lounge,
                    'label' => $category_name,
                ],
                'add_status' => $add_status,
            ];
        }

        return null;
    }

    /**
     * API method
     * Adds a chunk of an image. Chunks don't have to be uploaded in the right sort order. When the last chunk is added, they get merged.
     * @since 11
     * @param array{username?: string, password: string|null, chunk: int, chunk_sum: string, chunks: int, original_sum: string, category: array<int, int>, filename: string, name: string|null, author: string|null, comment: string|null, date_creation: string|null, level: int, tag_ids: string|null, image_id: int|null, ...} $params
     *    username: WsParamFlag::OPTIONAL, no 'default' -- may be entirely absent
     *    from $params. password: WsParamFlag::OPTIONAL, null default. chunk/
     *    chunks: WsParamType::INT|POSITIVE, mandatory -- always int. chunk_sum/
     *    original_sum/filename: no WS_TYPE flag, mandatory -- always string.
     *    category: WsParamFlag::FORCE_ARRAY|WsParamType::ID, null default -- never
     *    null (makeArrayParam() converts to []). name/author/comment/
     *    date_creation/tag_ids: no WS_TYPE flag, null default -- string|null.
     *    level: WsParamType::INT|POSITIVE, default 0 (non-null) -- always int.
     *    image_id: WsParamType::ID, null default -- int|null.
     *
     * Return type genuinely can't be narrower than mixed: the success path
     * forwards $service->invoke('pwg.images.getInfo', ...)'s own result --
     * same PwgServer::invoke() by-name-dispatcher rationale as
     * Ws\PwgUsers::add()/setInfo().
     */
    public function uploadAsync(array $params, PwgServer &$service): mixed
    {
        $logger = $this->currentLogger->get();

        // the username/password parameters have been used in include/user.inc.php
        // to authenticate the request (a much better time/place than here)

        // additional check for some parameters
        if (! (bool) preg_match('/^[a-fA-F0-9]{32}$/', $params['original_sum'])) {
            return new PwgError(WsError::INVALID_PARAM, 'Invalid original_sum');
        }

        if ($params['image_id'] > 0) {
            if (! $this->imageService->existsById(ImageId::from($params['image_id']))) {
                return new PwgError(404, __FUNCTION__ . ' : image_id not found');
            }
        }

        $upload_dir_conf = $this->paths->root . $this->currentConfig->uploadDir();
        $output_filepath_prefix = $upload_dir_conf . '/buffer/' . $params['original_sum'] . '-u' . $this->currentUser->get()->id->value;
        $chunkfile_path_pattern = $output_filepath_prefix . '-%03uof%03u.chunk';

        $chunkfile_path = sprintf($chunkfile_path_pattern, $params['chunk'] + 1, $params['chunks']);

        // create the upload directory tree if not exists
        if (! FilesystemHelper::mkgetdir(dirname($chunkfile_path), $this->currentConfig, FilesystemHelper::MKGETDIR_DEFAULT & ~FilesystemHelper::MKGETDIR_DIE_ON_ERROR)) {
            return new PwgError(500, 'error during buffer directory creation');
        }
        FilesystemHelper::secureDirectory(dirname($chunkfile_path));

        // move uploaded file
        $uploaded_chunk_tmp_name = UploadedFileRequest::fromFilesKey('file')->tmpName;
        if ($uploaded_chunk_tmp_name === null) {
            return new PwgError(500, 'missing uploaded chunk file');
        }
        // $chunkfile_path is already absolute ($upload_dir_conf above
        // includes the $this->paths->root prefix) -- just normalize
        // backslashes/'/./' segments before stripRoot() can compute the
        // 'uploads' disk-relative path; everything downstream keeps using
        // the original absolute $chunkfile_path unchanged, since the
        // 'uploads' disk is rooted at the same real filesystem location.
        $paths = $this->paths;
        $chunk_root = $paths->root . $this->currentConfig->uploadDir();
        $chunk_abs_path = str_replace(['\\', '/./'], ['/', '/'], $chunkfile_path);
        $chunk_rel_path = StorageRegistry::stripRoot($chunk_root, $chunk_abs_path);
        $chunk_stream = fopen($uploaded_chunk_tmp_name, 'rb');
        if ($chunk_stream !== false) {
            $this->storageRegistry->get('uploads')
                ->writeStream($chunk_rel_path, $chunk_stream);
            fclose($chunk_stream);
        }
        $logger->debug(__FUNCTION__ . ' uploaded ' . $chunkfile_path);

        // MD5 checksum
        $chunk_md5 = md5_file($chunkfile_path);
        if ($chunk_md5 !== $params['chunk_sum']) {
            unlink($chunkfile_path);
            $logger->error(__FUNCTION__ . ' ' . $chunkfile_path . ' MD5 checksum mismatched');
            return new PwgError(500, 'MD5 checksum chunk file mismatched');
        }

        // are all chunks uploaded?
        $chunk_ids_uploaded = [];
        for ($i = 1; $i <= $params['chunks']; $i++) {
            $chunkfile = sprintf($chunkfile_path_pattern, $i, $params['chunks']);
            if (file_exists($chunkfile) && ($fp = fopen($chunkfile, 'rb')) !== false) {
                $chunk_ids_uploaded[] = $i;
                fclose($fp);
            }
        }

        if ($params['chunks'] !== count($chunk_ids_uploaded)) {
            // all chunks are not yet available
            $logger->debug(__FUNCTION__ . ' all chunks are not uploaded yet, maybe on next chunk, exit for now');
            return [
                'message' => 'chunks uploaded = ' . implode(',', $chunk_ids_uploaded),
            ];
        }

        // all chunks available
        $logger->debug(__FUNCTION__ . ' ' . $params['original_sum'] . ' ' . $params['chunks'] . ' chunks available, try now to get lock for merging');
        $output_filepath = $output_filepath_prefix . '.merged';

        // chunks already being merged?
        if (file_exists($output_filepath) && ($fp = fopen($output_filepath, 'rb')) !== false) {
            // merge file already exists
            fclose($fp);
            $logger->error(__FUNCTION__ . ' ' . $output_filepath . ' already exists, another merge is under process');
            return [
                'message' => 'chunks uploaded = ' . implode(',', $chunk_ids_uploaded),
            ];
        }

        // create merged and open it for writing only
        $fp = fopen($output_filepath, 'wb');
        if (! (bool) $fp) {
            // unable to create file and open it for writing only
            $logger->error(__FUNCTION__ . ' ' . $chunkfile_path . ' unable to create merge file');
            return new PwgError(500, 'error while creating merged ' . $chunkfile_path);
        }

        // acquire an exclusive lock and keep it until merge completes
        // this postpones another uploadAsync task running in another thread
        if (! flock($fp, LOCK_EX)) {
            // unable to obtain lock
            fclose($fp);
            $logger->error(__FUNCTION__ . ' ' . $chunkfile_path . ' unable to obtain lock');
            return new PwgError(500, 'error while locking merged ' . $chunkfile_path);
        }

        $logger->debug(__FUNCTION__ . ' lock obtained to merge chunks');

        // loop over all chunks
        foreach ($chunk_ids_uploaded as $chunk_id) {
            $chunkfile_path = sprintf($chunkfile_path_pattern, $chunk_id, $params['chunks']);

            // chunk deleted by preceding merge?
            if (! file_exists($chunkfile_path)) {
                // cancel merge
                $logger->error(__FUNCTION__ . ' ' . $chunkfile_path . ' already merged');
                flock($fp, LOCK_UN);
                fclose($fp);
                return [
                    'message' => 'chunks uploaded = ' . implode(',', $chunk_ids_uploaded),
                ];
            }

            $chunk_contents = file_get_contents($chunkfile_path);
            if ($chunk_contents === false || ! (bool) fwrite($fp, $chunk_contents)) {
                // could not append chunk
                $logger->error(__FUNCTION__ . ' error merging chunk ' . $chunkfile_path);
                flock($fp, LOCK_UN);
                fclose($fp);

                // delete merge file without returning an error
                @unlink($output_filepath);
                return new PwgError(500, 'error while merging chunk ' . $chunk_id);
            }

            $logger->debug(__FUNCTION__ . ' original_sum=' . $params['original_sum'] . ', chunk ' . $chunk_id . '/' . $params['chunks'] . ' merged');

            // delete chunk and clear cache
            unlink($chunkfile_path);
        }

        // flush output before releasing lock
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $logger->debug(__FUNCTION__ . ' merged file ' . $output_filepath . ' saved');

        // MD5 checksum
        $merged_md5 = md5_file($output_filepath);

        if ($merged_md5 !== $params['original_sum']) {
            unlink($output_filepath);
            $logger->error(__FUNCTION__ . ' ' . $output_filepath . ' MD5 checksum mismatched!');
            return new PwgError(500, 'MD5 checksum merged file mismatched');
        }

        $logger->debug(__FUNCTION__ . ' ' . $output_filepath . ' MD5 checksum OK');

        $image_id = $this->uploadService
            ->addUploadedFile(
                $output_filepath,
                $this->urlService,
                $params['filename'],
                $params['category'],
                $params['level'],
                $params['image_id'],
                $params['original_sum'],
                $service
            );

        $logger->debug(__FUNCTION__ . ' image_id after add_uploaded_file = ' . $image_id);

        // and now, let's create tag associations
        if (isset($params['tag_ids']) and $params['tag_ids'] !== '') {
            $this->tagService
                ->setTags(
                    array_values(array_filter(array_map(TagId::tryFrom(...), explode(',', $params['tag_ids'])))),
                    $image_id
                );
        }

        // time to set other infos
        $this->imageService->updateDescriptiveFields(
            ImageId::from($image_id),
            name: is_string($params['name']) ? $params['name'] : null,
            author: is_string($params['author']) ? $params['author'] : null,
            comment: is_string($params['comment']) ? $params['comment'] : null,
            dateCreation: is_string($params['date_creation']) ? $params['date_creation'] : null,
        );

        // final step, reset user cache
        PermissionCacheInvalidator::invalidate();

        // trick to bypass get_sql_condition_FandF
        if ($params['level'] !== 0 and $params['level'] > $this->currentUser->get()->level) {
            // this will not persist -- CurrentUser is the only reader of
            // this in-memory level override.
            $this->currentUser->set($this->currentUser->get()->withLevel($params['level']));
        }

        // delete chunks older than a week
        $now = time();
        $chunk_files = glob($upload_dir_conf . '/buffer/*.chunk');
        foreach (($chunk_files !== false ? $chunk_files : []) as $file) {
            if (is_file($file)) {
                $file_mtime = filemtime($file);
                // filemtime() can race with a concurrent cleanup pass removing
                // $file between the is_file() check above and here; skip it
                // this round rather than treat a failed stat as "old".
                if ($file_mtime !== false && $now - $file_mtime >= 60 * 60 * 24 * 7) { // 7 days
                    $logger->info(__FUNCTION__ . ' delete ' . $file);
                    unlink($file);
                } else {
                    $logger->debug(__FUNCTION__ . ' keep ' . $file);
                }
            }
        }

        // delete merged older than a week
        $merged_files = glob($upload_dir_conf . '/buffer/*.merged');
        foreach (($merged_files !== false ? $merged_files : []) as $file) {
            if (is_file($file)) {
                $file_mtime = filemtime($file);
                // filemtime() can race with a concurrent cleanup pass removing
                // $file between the is_file() check above and here; skip it
                // this round rather than treat a failed stat as "old".
                if ($file_mtime !== false && $now - $file_mtime >= 60 * 60 * 24 * 7) { // 7 days
                    $logger->info(__FUNCTION__ . ' delete ' . $file);
                    unlink($file);
                } else {
                    $logger->debug(__FUNCTION__ . ' keep ' . $file);
                }
            }
        }

        return $service->invoke('pwg.images.getInfo', [
            'image_id' => $image_id,
        ]);
    }

    /**
     * API method
     * Check if an image exists by it's name or md5 sum
     * @param array{md5sum_list: string|null, filename_list: string|null, ...} $params
     *    both: no WS_TYPE flag, null default -- string|null.
     * @return array<string, int|string|null> keyed by md5sum/filename;
     *   id is Tables::images()'s NOT NULL primary key (int|string per
     *   driver), or null when no matching photo was found
     */
    public function exist(array $params, PwgServer $service): array
    {
        $logger = $this->currentLogger->get();

        $logger->debug(__FUNCTION__, 'WS', $params);

        $split_pattern = '/[\s,;\|]/';
        $result = [];

        if ($this->currentConfig->uniquenessMode() === 'md5sum') {
            // search among photos the list of photos already added, based on md5sum list
            $md5sums = preg_split(
                $split_pattern,
                (string) $params['md5sum_list'],
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            if ($md5sums === false) {
                throw new Exception('ws_images_exist(): preg_split() failed');
            }

            $id_of_md5 = $this->imageService->getIdsByMd5sums($md5sums);

            foreach ($md5sums as $md5sum) {
                $result[$md5sum] = $id_of_md5[$md5sum] ?? null;
            }
        } elseif ($this->currentConfig->uniquenessMode() === 'filename') {
            // search among photos the list of photos already added, based on
            // filename list
            $filenames = preg_split(
                $split_pattern,
                (string) $params['filename_list'],
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            if ($filenames === false) {
                throw new Exception('ws_images_exist(): preg_split() failed');
            }

            $id_of_filename = $this->imageService->getIdsByFilenames($filenames);

            foreach ($filenames as $filename) {
                $result[$filename] = $id_of_filename[$filename] ?? null;
            }
        }

        return $result;
    }

    /**
     * API method
     * Checks, for each candidate filename supplied by the client, whether a
     * matching photo already exists (by filename with known format
     * extensions stripped) and whether a format with that extension is
     * already associated with it
     *
     * @since 13
     * @param array{filename_list: string, ...} $params filename_list: no
     *    WS_TYPE flag, mandatory -- always a plain string.
     * Result rows are genuinely polymorphic (status: 'not found'|'multiple'
     * carry no other key, status: 'found' adds image_id/format_exist), and
     * $candidates below is arbitrary client-supplied JSON.
     * @return array<int|string, array<string, mixed>>
     */
    public function formatsSearchImage(array $params, PwgServer $service): array
    {
        $logger = $this->currentLogger->get();

        $logger->debug(__FUNCTION__, 'WS', $params);

        $candidates = json_decode(stripslashes($params['filename_list']), true);
        if (! is_array($candidates)) {
            $candidates = [];
        }
        /** @var array<int|string, mixed> $candidates */
        $unique_filenames_db = [];

        foreach ($this->imageService->getAllIdsAndFiles() as $row) {
            $filename_wo_ext = StringHelper::getFilenameWoExtension($row->file);
            @$unique_filenames_db[$filename_wo_ext][] = $row->id;
        }

        // we want "long" format extensions first to match "cmyk.jpg" before "jpg" for example
        // (kept as a local variable, not written back to $conf -- $conf is
        // reloaded from scratch on every request, so mutating it here
        // wouldn't persist anyway)
        $format_ext_list = $this->currentConfig->formatExtensions();
        usort($format_ext_list, static fn (string $a, string $b): int => strlen($b) - strlen($a));

        $format_db = [];
        foreach ($this->imageService->getAllImageIdsAndExts() as $row) {
            $format_image_id = $row->imageId;
            @$format_db[$format_image_id][] = $row->ext;
        }

        $result = [];

        foreach ($candidates as $format_external_id => $format_filename) {
            $candidate_filename_wo_ext = null;

            if (! is_string($format_filename)) {
                $result[$format_external_id] = [
                    'status' => 'not found',
                ];
                continue;
            }

            if ((bool) preg_match('/^(.*?)\.(' . implode('|', $format_ext_list) . ')$/', $format_filename, $matches)) {
                $candidate_filename_wo_ext = $matches[1];
            }

            if (! is_string($candidate_filename_wo_ext) || $candidate_filename_wo_ext === '') {
                $result[$format_external_id] = [
                    'status' => 'not found',
                ];
                continue;
            }

            if (isset($unique_filenames_db[$candidate_filename_wo_ext])) {
                if (count($unique_filenames_db[$candidate_filename_wo_ext]) > 1) {
                    $result[$format_external_id] = [
                        'status' => 'multiple',
                    ];
                    continue;
                }
                $img_id = $unique_filenames_db[$candidate_filename_wo_ext][0];
                $mult_form = false;
                if (isset($format_db[$img_id])) {
                    $format_ext = pathinfo($format_filename, PATHINFO_EXTENSION);
                    if (array_search($format_ext, array_map(strval(...), array_filter($format_db[$img_id], is_scalar(...))), true) !== false) {
                        $mult_form = true;
                    }
                }
                $result[$format_external_id] = [
                    'status' => 'found',
                    'image_id' => $img_id,
                    'format_exist' => $mult_form,
                ];
                continue;
            }

            $result[$format_external_id] = [
                'status' => 'not found',
            ];
        }

        return $result;
    }

    /**
     * API method
     * Remove a formats from the database and the file system
     *
     * @since 13
     * @param array{format_id: int|array<int, int>|null, pwg_token: string, ...} $params
     *    format_id: WsParamType::ID + WsParamFlag::ACCEPT_ARRAY, null default -- a
     *    plain int, a list of ints, or null. pwg_token: no WS_TYPE flag,
     *    mandatory -- always a plain string.
     */
    public function formatsDelete(array $params, PwgServer $service): PwgError|bool
    {
        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (! is_array($params['format_id'])) {
            $format_id_list = preg_split(
                '/[\s,;\|]/',
                (string) $params['format_id'],
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            if ($format_id_list === false) {
                throw new Exception('ws_images_formats_delete(): preg_split() failed');
            }
            $params['format_id'] = $format_id_list;
        }
        $params['format_id'] = array_map(intval(...), $params['format_id']);

        $format_ids = [];
        foreach ($params['format_id'] as $format_id) {
            if ($format_id >= 0) {
                $format_ids[] = $format_id;
            }
        }

        $image_ids = [];
        $formats_of = [];

        // Delete physical file
        $ok = true;

        foreach ($this->imageService->getImageIdsAndExtsByFormatIds($format_ids) as $row) {
            if (! isset($formats_of[$row->imageId])) {
                $image_ids[] = $row->imageId;
                $formats_of[$row->imageId] = [];
            }

            $formats_of[$row->imageId][] = $row->ext;
        }

        if (count($image_ids) === 0) {
            return new PwgError(404, 'No format found for the id(s) given');
        }

        $urlService = $this->urlService;
        foreach ($this->imageService->getPathsForFileDeletion($image_ids) as $image_row) {
            if ($urlService->urlIsRemote($image_row->path)) {
                continue;
            }

            $files = [];
            $image_path = ImagePathHelper::getElementPath($image_row->toArray(), $urlService, $this->paths);

            if (isset($formats_of[$image_row->id])) {
                foreach ($formats_of[$image_row->id] as $format_ext) {
                    $files[] = ImagePathHelper::originalToFormat($image_path, $format_ext);
                }
            }

            foreach ($files as $path) {
                if (is_file($path) and ! unlink($path)) {
                    $ok = false;
                    trigger_error('"' . $path . '" cannot be removed', E_USER_WARNING);
                    break;
                }
            }
        }

        // Delete format in the database
        $this->imageService->deleteFormatsByIds($format_ids);

        PermissionCacheInvalidator::invalidate();

        return $ok;
    }

    /**
     * API method
     * Check is file has been update
     * @param array{image_id: int, file_sum: string|null, thumbnail_sum: string|null, high_sum: string|null, ...} $params
     *    image_id: WsParamType::ID, mandatory -- always int. file_sum/
     *    thumbnail_sum/high_sum: no WS_TYPE flag, null default -- string|null.
     * @return PwgError|array<string, string>
     */
    public function checkFiles(array $params, PwgServer $service): PwgError|array
    {
        $logger = $this->currentLogger->get();

        $logger->debug(__FUNCTION__, 'WS', $params);

        $path = $this->imageService->getPathById(ImageId::from($params['image_id']));

        if ($path === null) {
            return new PwgError(404, 'image_id not found');
        }
        // `path` is stored root-relative (e.g. "upload/2026/.../foo.jpg") --
        // resolve it to a real filesystem path the same way
        // formatsDelete()/DerivativeImage do (ImagePathHelper::
        // getElementPath()), rather than handing the bare DB value straight
        // to md5_file() below, which silently fails (false, never equal to
        // any real hash) for every non-remote photo.
        $path = ImagePathHelper::getElementPath(
            [
                'path' => $path,
            ],
            $this->urlService,
            $this->paths
        );

        $ret = [];

        if (isset($params['thumbnail_sum'])) {
            // We always say the thumbnail is equal to create no reaction on the
            // other side. Since Piwigo 2.4 and derivatives, the thumbnails and web
            // sizes are always generated by Piwigo
            $ret['thumbnail'] = 'equals';
        }

        if (isset($params['high_sum'])) {
            $ret['file'] = 'equals';
            $compare_type = 'high';
        } elseif (isset($params['file_sum'])) {
            $compare_type = 'file';
        }

        if (isset($compare_type)) {
            $path_md5sum = md5_file($path);
            $logger->debug(__FUNCTION__ . ', md5_file($path) = ' . ($path_md5sum === false ? '' : $path_md5sum), 'WS');
            if ($path_md5sum !== $params[$compare_type . '_sum']) {
                $ret[$compare_type] = 'differs';
            } else {
                $ret[$compare_type] = 'equals';
            }
        }

        $logger->debug(__FUNCTION__, 'WS', $ret);

        return $ret;
    }

    /**
     * API method
     * Sets details of an image
     * @param array{image_id: int, file: string|null, name: string|null, author: string|null, date_creation: string|null, comment: string|null, categories: string|null, tag_ids: string|null, level: int|null, single_value_mode: string, multiple_value_mode: string, pwg_token?: string, ...} $params
     *    image_id: WsParamType::ID, mandatory -- always int. file/name/author/
     *    date_creation/comment/categories/tag_ids: no WS_TYPE flag, null
     *    default -- string|null. level: WsParamType::INT|POSITIVE, null default --
     *    int|null. single_value_mode/multiple_value_mode: no WS_TYPE flag,
     *    non-null string defaults -- always string. pwg_token:
     *    WsParamFlag::OPTIONAL with no 'default' key -- may be entirely absent.
     */
    public function setInfo(array $params, PwgServer $service): ?PwgError
    {

        if (isset($params['pwg_token']) and new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $imageId = ImageId::tryFrom($params['image_id']);
        $imageRow = $imageId === null ? null : $this->imageRepository->findById($imageId);

        if ($imageRow === null) {
            return new PwgError(404, 'image_id not found');
        }
        // Unboxed here rather than kept as the typed object -- this method
        // reads $image_row[$key] for a dynamically-iterated column name
        // list below, not a fixed set of named properties.
        $image_row = $imageRow->toArray();

        // database registration
        $update = [];

        $info_columns = [
            'name',
            'author',
            'comment',
            'level',
            'date_creation',
        ];

        foreach ($info_columns as $key) {
            if (isset($params[$key])) {
                if (! $this->currentConfig->allowHtmlDescriptions() or ! isset($params['pwg_token'])) {
                    $params[$key] = strip_tags((string) $params[$key], '<b><strong><em><i>');
                }

                if ($params['single_value_mode'] === 'fill_if_empty') {
                    // $image_row[$key] is int|null|string for every key in
                    // $info_columns (Image::toArray()) -- false/0.0/[] can
                    // never actually occur, so they're dropped from the
                    // haystack rather than kept as unreachable dead entries.
                    if (in_array($image_row[$key], [null, 0, '0', ''], true)) {
                        $update[$key] = $params[$key];
                    }
                } elseif ($params['single_value_mode'] === 'replace') {
                    $update[$key] = $params[$key];
                } else {
                    return new PwgError(
                        500,
                        '[ws_images_setInfo]'
          . ' invalid parameter single_value_mode "' . $params['single_value_mode'] . '"'
          . ', possible values are {fill_if_empty, replace}.'
                    );
                }
            }
        }

        if (isset($params['file'])) {
            if (($image_row['storage_category_id'] ?? 0) !== 0) {
                return new PwgError(
                    500,
                    '[ws_images_setInfo] updating "file" is forbidden on photos added by synchronization'
                );
            }

            // prevent XSS, remove HTML tags
            $update['file'] = strip_tags($params['file']);
            if ($update['file'] === '' || $update['file'] === '0') {
                unset($update['file']);
            }
        }

        if (count(array_keys($update)) > 0) {
            $this->imageService->updateFields($imageId, $update);
            $this->entityManager->clear();

            $this->activityService->record('photo', $params['image_id'], 'edit');
        }

        if (isset($params['categories'])) {
            $this->addImageCategoryRelations(
                $imageId,
                $params['categories'],
                ($params['multiple_value_mode'] === 'replace' ? true : false)
            );
        }

        // and now, let's create tag associations
        $tagService = $this->tagService;

        if (isset($params['tag_ids'])) {
            $tag_ids = [];

            foreach (explode(',', $params['tag_ids']) as $candidate) {
                $candidate = trim($candidate);

                if ((bool) preg_match(ValidationPattern::ID, $candidate)) {
                    $tag_ids[] = TagId::from((int) $candidate);
                }
            }

            if ($params['multiple_value_mode'] === 'replace') {
                $tagService->setTags(
                    $tag_ids,
                    $params['image_id']
                );
            } elseif ($params['multiple_value_mode'] === 'append') {
                $tagService->addTags(
                    $tag_ids,
                    [$params['image_id']]
                );
            } else {
                return new PwgError(
                    500,
                    '[ws_images_setInfo]'
        . ' invalid parameter multiple_value_mode "' . $params['multiple_value_mode'] . '"'
        . ', possible values are {replace, append}.'
                );
            }
        }

        // Temporary use of the batch manager's unit mode,
        // not to be used by an external application,
        // as this code bellow will be deleted when a tag selector is added.
        $tagListRequest = TagListRequest::fromGlobals();
        if ($tagListRequest->present) {
            if (isset($params['tag_ids'])) {
                return new PwgError(WsError::INVALID_PARAM, 'Do not use tag_list and tag_ids at the same time.');
            }

            // TagService::getTagIds()/tagIdFromTagName() go through
            // TagRepository's parameterized DBAL queries, so no manual
            // escaping is needed here.
            $cleaned_tag_list = [];
            foreach ($tagListRequest->items as $tag_candidate) {
                $cleaned_tag_list[] = strip_tags(stripslashes(is_string($tag_candidate) ? $tag_candidate : ''));
            }

            $tag_list = $tagService->getTagIds($cleaned_tag_list);
            $tagService->setTags($tag_list, $params['image_id']);
        }

        PermissionCacheInvalidator::invalidate();

        return null;
    }

    /**
     * API method
     * Deletes an image
     * @param array{image_id: string|array<array-key, string>, pwg_token: string, ...} $params
     *    image_id: WsParamFlag::ACCEPT_ARRAY (not FORCE), no WS_TYPE flag,
     *    mandatory -- a plain string or an array, never null. pwg_token: no
     *    WS_TYPE flag, mandatory -- always a plain string.
     */
    public function delete(array $params, PwgServer $service): PwgError|int
    {
        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (! is_array($params['image_id'])) {
            $image_id_list = preg_split(
                '/[\s,;\|]/',
                $params['image_id'],
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            if ($image_id_list === false) {
                throw new Exception(__FUNCTION__ . '(): preg_split() failed');
            }
            $params['image_id'] = $image_id_list;
        }
        $params['image_id'] = array_map(intval(...), $params['image_id']);

        $image_ids = [];
        foreach ($params['image_id'] as $image_id) {
            if ($image_id > 0) {
                $image_ids[] = $image_id;
            }
        }

        $ret = $this->imageService
            ->deleteElements($image_ids, $this->urlService, true);
        PermissionCacheInvalidator::invalidate();

        return $ret;
    }

    /**
     * API method
     * Checks if Piwigo is ready for upload
     * @param mixed[] $params
     * @return array{message: ?string, ready_for_upload: bool}
     */
    public function checkUpload(array $params, PwgServer $service): array
    {
        $ret = [];
        $ret['message'] = $this->uploadService->readyForUploadMessage();
        $ret['ready_for_upload'] = true;
        if (! in_array($ret['message'], [null, ''], true)) {
            $ret['ready_for_upload'] = false;
        }

        return $ret;
    }

    /**
     * API method
     * Empties the lounge, where photos may wait before taking off.
     * @since 12
     * @param mixed[] $params
     * @return array{rows: list<array{image_id: int, category_id: int}>|null} matches
     *   ImageService::emptyLounge()'s own already-precise return type
     */
    public function emptyLounge(array $params, PwgServer $service): array
    {
        $ret = [
            'rows' => $this->imageService
                ->emptyLounge(),
        ];

        return $ret;
    }

    /**
     * API method
     * Notify Piwigo you have finished uploading a set of photos.
     * @since 12
     * @param array{image_id: string|array<array-key, string>|null, pwg_token: string, category_id: int, ...} $params
     *    image_id: WsParamFlag::ACCEPT_ARRAY (not FORCE), no WS_TYPE flag, null
     *    default -- string, array, or null. pwg_token: no WS_TYPE flag,
     *    mandatory -- always string. category_id: WsParamType::ID, mandatory --
     *    always int.
     * @return PwgError|array{moved_from_lounge: list<array{image_id: int, category_id: int}>|null, category: array{id: int, nb_photos: int, label: string}}
     */
    public function uploadCompleted(array $params, PwgServer $service): PwgError|array
    {
        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if ($params['image_id'] === null) {
            // documented null default (no image_id filter provided) -- treat
            // the same as an empty list rather than reaching preg_split()
            // with a null subject.
            $params['image_id'] = [];
        } elseif (! is_array($params['image_id'])) {
            $image_id_list = preg_split(
                '/[\s,;\|]/',
                $params['image_id'],
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            if ($image_id_list === false) {
                throw new Exception(__FUNCTION__ . '(): preg_split() failed');
            }
            $params['image_id'] = $image_id_list;
        }
        $params['image_id'] = array_map(intval(...), $params['image_id']);

        $image_ids = [];
        foreach ($params['image_id'] as $image_id) {
            if ($image_id > 0) {
                $image_ids[] = $image_id;
            }
        }

        // the list of images moved from the lounge might not be the same than
        // $image_ids (canbe a subset or more image_ids from another upload too)
        $moved_from_lounge = $this->imageService
            ->emptyLounge();

        $nb_photos = $this->imageService->countImagesInCategory(CategoryId::from($params['category_id']));
        $category_name = $this->htmlService
            ->getCatDisplayNameFromId($params['category_id'], null);

        $this->eventDispatcher->dispatchNotify(new WsImagesUploadCompleted([
            'image_ids' => $image_ids,
            'category_id' => $params['category_id'],
            'moved_from_lounge' => $moved_from_lounge,
        ]));

        return [
            'moved_from_lounge' => $moved_from_lounge,
            'category' => [
                'id' => $params['category_id'],
                'nb_photos' => $nb_photos,
                'label' => $category_name,
            ],
        ];
    }

    /**
     * API method
     * add md5sum at photos, by block. Returns how md5sum were added and how many are remaining.
     * @param array{block_size: int, pwg_token: string, ...} $params
     *    block_size: WsParamType::INT|POSITIVE, default is a non-null $conf value
     *    -- always int. pwg_token: no WS_TYPE flag, mandatory -- always string.
     * @return PwgError|array{nb_added: int, nb_no_md5sum: int}
     */
    public function setMd5sum(array $params, PwgServer $service): PwgError|array
    {
        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $imageService = $this->imageService;

        $no_md5sum_ids = $imageService->getPhotosNoMd5sum();
        $added_count = 0;

        if (count($no_md5sum_ids) > 0) {
            $md5sum_ids_to_add = array_slice($no_md5sum_ids, 0, $params['block_size']);
            $added_count = $imageService->addMd5sum($md5sum_ids_to_add);
        }

        return [
            'nb_added' => $added_count,
            'nb_no_md5sum' => count($imageService->getPhotosNoMd5sum()),
        ];
    }

    /**
     * API method
     * Synchronize metadatas photos. Returns how many metadatas were sync.
     * @param array{image_id: string|array<array-key, string>, pwg_token: string, ...} $params
     *    image_id: WsParamFlag::ACCEPT_ARRAY, no WS_TYPE flag, mandatory -- a
     *    plain string or an array, never null. pwg_token: mandatory string.
     * @return PwgError|array{nb_synchronized: int}
     */
    public function syncMetadata(array $params, PwgServer $service): PwgError|array
    {
        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (! is_array($params['image_id'])) {
            $image_id_list = preg_split(
                '/[\s,;\|]/',
                $params['image_id'],
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            if ($image_id_list === false) {
                throw new Exception(__FUNCTION__ . '(): preg_split() failed');
            }
            $params['image_id'] = $image_id_list;
        }

        $image_ids = [];
        foreach ($params['image_id'] as $image_id) {
            $image_id = trim($image_id);

            if (! (bool) preg_match(ValidationPattern::ID, $image_id)) {
                return new PwgError(WsError::INVALID_PARAM, 'Invalid image_id "' . $image_id . '"');
            }

            $image_ids[] = $image_id;
        }

        if ($image_ids === []) {
            return new PwgError(WsError::INVALID_PARAM, 'Invalid image_id (no value after filters)');
        }

        $image_ids = $this->imageService->getExistingIds($image_ids);

        if ($image_ids === []) {
            return new PwgError(403, 'No image found');
        }

        $this->metadataService
            ->syncMetadata($image_ids);

        return [
            'nb_synchronized' => count($image_ids),
        ];
    }

    /**
     * API method
     * Deletes orphan photos, by block. Returns how many orphans were deleted and how many are remaining.
     * @param array{block_size: int, pwg_token: string, ...} $params
     *    block_size: WsParamType::INT|POSITIVE, default 1000 (non-null) -- always
     *    int. pwg_token: no WS_TYPE flag, mandatory -- always string.
     * @return PwgError|array{nb_deleted: int, nb_orphans: int}
     */
    public function deleteOrphans(array $params, PwgServer $service): PwgError|array
    {
        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $imageService = $this->imageService;

        $orphan_ids_to_delete = array_slice($imageService->getOrphans(), 0, $params['block_size']);
        $deleted_count = $imageService->deleteElements($orphan_ids_to_delete, $this->urlService, true);
        PermissionCacheInvalidator::invalidate();

        return [
            'nb_deleted' => $deleted_count,
            'nb_orphans' => count($imageService->getOrphans()),
        ];
    }

    /**
     * API method
     * Associate/Dissociate/Move photos with an album.
     *
     * @since 14
     * @param array{image_id: array<int, int>, category_id: int, action: string, pwg_token: string, ...} $params
     *    image_id: WsParamFlag::FORCE_ARRAY|WsParamType::ID -- always a list of positive
     *    ints. category_id: WsParamType::ID, mandatory. action/pwg_token: no
     *    WS_TYPE flag, but always plain strings (action has a string default,
     *    pwg_token is mandatory)
     */
    public function setCategory(array $params, PwgServer $service): ?PwgError
    {
        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        // does the category really exist?
        if (! $this->categoryService->existsById($params['category_id'])) {
            return new PwgError(404, 'category_id not found');
        }

        $imageService = $this->imageService;

        if ($params['action'] === 'associate') {
            $imageService->associateImagesToCategories($params['image_id'], [$params['category_id']]);
        } elseif ($params['action'] === 'dissociate') {
            $imageService->dissociateImagesFromCategory($params['image_id'], $params['category_id']);
        } elseif ($params['action'] === 'move') {
            $imageService->moveImagesToCategories($params['image_id'], [$params['category_id']]);
        }

        PermissionCacheInvalidator::invalidate();

        return null;
    }
}
