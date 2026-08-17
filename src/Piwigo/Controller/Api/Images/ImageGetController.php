<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Category\Event\RenderCategoryName;
use Piwigo\Comment\CommentService;
use Piwigo\Comment\Projection\CommentSummary;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Html\Event\RenderElementDescription;
use Piwigo\Html\Event\RenderElementName;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageUrlBuilder;
use Piwigo\Image\Projection\Image;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Rate\RateService;
use Piwigo\Tag\TagService;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/images/{id}` -- `pwg.images.getInfo`'s real replacement.
 * Public, permission-filtered (no `AdminGuard` here):
 * `PermissionService::getPermissionCriteria()` already decides which
 * images the calling session (guest included) can see. An orphan/lounge
 * photo (no visible related category) additionally requires an admin
 * session.
 */
final readonly class ImageGetController implements ControllerInterface
{
    public function __construct(
        private ImageService $imageService,
        private PermissionService $permissionService,
        private ImageUrlBuilder $imageUrlBuilder,
        private EventDispatcher $eventDispatcher,
        private TagService $tagService,
        private HtmlService $htmlService,
        private UrlServiceInterface $urlService,
        private AccessControl $accessControl,
        private RateService $rateService,
        private CommentService $commentService,
        private CurrentConfig $currentConfig,
        private CurrentUser $currentUser,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $routeArgs = $request->getAttribute('route_args');
        $rawId = is_array($routeArgs) ? ($routeArgs['id'] ?? null) : null;
        $imageId = is_string($rawId) ? (int) $rawId : 0;

        $query = $request->getQueryParams();
        $commentsPage = isset($query['commentsPage']) && is_numeric($query['commentsPage']) ? max(0, (int) $query['commentsPage']) : 0;
        $commentsPerPage = isset($query['commentsPerPage']) && is_numeric($query['commentsPerPage'])
            ? max(0, (int) $query['commentsPerPage'])
            : $this->currentConfig->nbCommentPage;

        $imageRow = $this->imageService->getRowWithCondition(ImageId::from($imageId), $this->permissionService->getPermissionCriteria());
        if ($imageRow === null) {
            return ResponseFactory::problem('Not Found', 404, 'image_id not found.');
        }

        $image = Image::fromRow($imageRow);
        $urls = $this->imageUrlBuilder->stdGetUrls($imageRow, $this->urlService);

        $nameEvent = $this->eventDispatcher->dispatch(new RenderElementName($image->name ?? '', 'getInfo'));
        $name = strip_tags($nameEvent->elementName);

        $descriptionEvent = $this->eventDispatcher->dispatch(new RenderElementDescription($image->comment ?? '', 'getInfo'));

        $relatedCategoryRows = $this->imageService->getRelatedCategoriesForImage(ImageId::from($imageId), $this->permissionService->getPermissionCriteria());

        $isCommentable = false;
        $relatedCategories = [];
        foreach ($relatedCategoryRows as $row) {
            if ($row->commentable) {
                $isCommentable = true;
            }

            $categoryNameEvent = $this->eventDispatcher->dispatch(new RenderCategoryName($row->name, 'getInfo'));
            $categoryRow = $row->toArray();

            $relatedCategories[] = [
                'id' => $row->id,
                'name' => strip_tags($categoryNameEvent->categoryName),
                'globalRank' => $row->globalRank,
                'url' => $this->urlService->makeIndexUrl([
                    'category' => $categoryRow,
                ]),
                'pageUrl' => $this->urlService->makePictureUrl([
                    'image_id' => $image->id->value,
                    'image_file' => $image->file,
                    'category' => $categoryRow,
                ]),
            ];
        }
        usort($relatedCategories, static fn (array $a, array $b): int => strnatcasecmp((string) $a['globalRank'], (string) $b['globalRank']));

        if ($relatedCategories === [] && ! $this->accessControl->isAdmin()) {
            return ResponseFactory::problem('Unauthorized', 401, 'Access denied.');
        }

        $relatedTags = [];
        foreach ($this->tagService->getCommonTags([$imageId], -1, $this->htmlService) as $tag) {
            $relatedTags[] = [
                'id' => $tag['id'],
                'name' => $tag['name'],
                'urlName' => $tag['url_name'],
                'url' => $this->urlService->makeIndexUrl([
                    'tags' => [$tag],
                ]),
                'pageUrl' => $this->urlService->makePictureUrl([
                    'image_id' => $image->id->value,
                    'image_file' => $image->file,
                    'tags' => [$tag],
                ]),
            ];
        }

        $rating = [
            'score' => $image->ratingScore,
            'count' => 0,
            'average' => null,
        ];
        if ($image->ratingScore !== null) {
            $rateSummary = $this->rateService->getRateSummaryForElement(ImageId::from($imageId));
            $rating['average'] = $rateSummary->average ?? 0.0;
            $rating['count'] = $rateSummary->count;
        }

        $onlyValidatedComments = ! $this->accessControl->isAdmin();
        $nbComments = $this->commentService->countForImage(ImageId::from($imageId), $onlyValidatedComments);

        $relatedComments = [];
        if ($nbComments > 0 && $commentsPerPage > 0) {
            $relatedComments = array_map(
                static fn (CommentSummary $summary): array => $summary->toArray(),
                $this->commentService->getSummariesForImage(ImageId::from($imageId), $onlyValidatedComments, $commentsPerPage, $commentsPerPage * $commentsPage)
            );
        }

        $commentPost = null;
        if (
            $this->currentConfig->activateComments
            && $isCommentable
            && (! $this->accessControl->isAGuest() || $this->currentConfig->commentsForall)
        ) {
            $commentPost = [
                'author' => $this->currentUser->get()
                    ->username->value ?? '',
                'key' => new EphemeralKeyService($this->currentConfig)
                    ->generate(2, (string) $imageId),
            ];
        }

        $body = [
            'id' => $image->id->value,
            'file' => $image->file,
            'name' => $name,
            'nameRaw' => $image->name,
            'comment' => $descriptionEvent->elementDescription,
            'commentRaw' => $image->comment,
            'author' => $image->author,
            'hit' => $image->hit,
            'filesize' => $image->filesize,
            'width' => $image->width,
            'height' => $image->height,
            'coi' => $image->coi,
            'dateAvailable' => $image->dateAvailable,
            'dateCreation' => $image->dateCreation,
            'dateMetadataUpdate' => $image->dateMetadataUpdate,
            'level' => $image->level,
            'md5sum' => $image->md5sum?->value,
            'addedBy' => $image->addedBy,
            'rotation' => $image->rotation,
            'latitude' => $image->latitude,
            'longitude' => $image->longitude,
            'lastmodified' => $image->lastmodified,
            'pageUrl' => $urls['page_url'],
            'elementUrl' => $urls['element_url'] ?? null,
            'downloadUrl' => $urls['download_url'],
            'derivatives' => $urls['derivatives'],
            'rating' => $rating,
            'categories' => $relatedCategories,
            'tags' => $relatedTags,
            'commentPost' => $commentPost,
            'commentsPaging' => [
                'page' => $commentsPage,
                'perPage' => $commentsPerPage,
                'count' => count($relatedComments),
                'totalCount' => $nbComments,
            ],
            'comments' => $relatedComments,
        ];

        return ResponseFactory::json($body);
    }
}
