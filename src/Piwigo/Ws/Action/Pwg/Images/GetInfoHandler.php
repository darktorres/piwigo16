<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Doctrine\DBAL\ParameterType;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentRepository;
use Piwigo\Config\Config;
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Event\Picture\RenderElementName;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Image\ImageRepository;
use Piwigo\Rate\RateRepository;
use Piwigo\Tag\TagService;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Ws\Encoder\PwgResponseEncoder;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsHelper;
use Psr\EventDispatcher\EventDispatcherInterface;

/** `pwg.images.getInfo` — full image record + permissioned categories, tags, rating, comments. */
final readonly class GetInfoHandler implements WsAction
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private CategoryService $categoryService,
        private CommentRepository $commentRepository,
        private EphemeralKeyService $ephemeralKeyService,
        private EventDispatcherInterface $dispatcher,
        private ImageRepository $imageRepository,
        private PermissionService $permissionService,
        private RateRepository $rateRepository,
        private TagService $tagService,
        private UrlService $urlService,
        private WsHelper $wsHelper,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        $input    = GetInfoParams::fromArray($params);
        $pImageId = $input->imageId;
        $perm  = $this->permissionService->getSqlConditionFandF(['visible_images' => 'id'], ' AND');
        $image = $this->imageRepository->findByIdWithPermissions($pImageId, $perm->where, $perm->params, $perm->types);
        if ($image === null) {
            return new PwgError(404, 'image_id not found');
        }
        $imageRowId   = $image->id->value;
        $imageRowFile = $image->file->value;
        $imageRow     = array_merge($image->toRow(), $this->wsHelper->getUrls($image->toRow()));
        $imageRow['name_raw'] = $image->name;
        $renderEvent          = new RenderElementName($image->name ?? '', $imageRow);
        $this->dispatcher->dispatch($renderEvent);
        $imageRow['name']        = strip_tags($renderEvent->elementName);
        $imageRow['comment_raw'] = $image->comment;
        $rowDescEvent            = new RenderElementDescription($image->comment ?? '', __FUNCTION__);
        $this->dispatcher->dispatch($rowDescEvent);
        $imageRow['comment'] = $rowDescEvent->elementDescription;
        $isCommentable       = false;
        $relatedCategories   = [];
        $relPerm = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'category_id'], ' AND');
        foreach ($this->categoryRepository->findRelatedCategoriesForImage($imageRowId, $relPerm->where, $relPerm->params, $relPerm->types) as $related) {
            if ($related->commentable) {
                $isCommentable = true;
            }
            $row             = $related->toUrlRow();
            $row['url']      = $this->urlService->makeIndexUrl(['category' => $row]);
            $row['page_url'] = $this->urlService->makePictureUrl(['image_id' => $imageRowId, 'image_file' => $imageRowFile, 'category' => $row]);
            $catRenderEvent  = new RenderCategoryName($related->name, __FUNCTION__);
            $this->dispatcher->dispatch($catRenderEvent);
            $row['name']         = strip_tags($catRenderEvent->categoryName);
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
            $whereComments .= ' AND validated = 1';
        }
        $nbComments       = $this->commentRepository->countByWhereFragment($whereComments, $commentParams, $commentTypes);
        $pCommentsPerPage = $input->commentsPerPage;
        $pCommentsPage    = $input->commentsPage;
        if ($nbComments > 0 && $pCommentsPerPage > 0) {
            foreach ($this->commentRepository->findByWhereFragmentOrderedByDate($whereComments, $pCommentsPerPage, $pCommentsPerPage * $pCommentsPage, $commentParams, $commentTypes) as $row) {
                $relatedComments[] = [
                    'id'      => $row->id->value,
                    'date'    => $row->date?->value,
                    'author'  => $row->author,
                    'content' => $row->content,
                ];
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
        $ret['rates']      = [PwgResponseEncoder::ATTRIBUTES_KEY => $rating];
        $ret['categories'] = new PwgNamedArray($relatedCategories, 'category', ['id', 'url', 'page_url']);
        $ret['tags']       = new PwgNamedArray($relatedTags, 'tag', $this->wsHelper->getTagXmlAttributes());
        if (isset($commentPostData)) {
            $ret['comment_post'] = [PwgResponseEncoder::ATTRIBUTES_KEY => $commentPostData];
        }
        $ret['comments_paging'] = new PwgNamedStruct(['page' => $input->commentsPage, 'per_page' => $input->commentsPerPage, 'count' => count($relatedComments), 'total_count' => $nbComments]);
        $ret['comments']        = new PwgNamedArray($relatedComments, 'comment', ['id', 'date']);
        if ($server->getResponseFormat() !== 'rest') {
            return $ret;
        }
        return ['image' => new PwgNamedStruct($ret, null, ['name', 'comment'])];
    }
}
