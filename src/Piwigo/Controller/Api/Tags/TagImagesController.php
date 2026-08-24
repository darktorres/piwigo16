<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Tags;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\PhotoSortOrder;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Controller\Api\ImageFilterQueryInput;
use Piwigo\Core\OperationError;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\TypedRepository;
use Piwigo\Html\Event\RenderElementDescription;
use Piwigo\Html\Event\RenderElementName;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageFilterCriteriaBuilder;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageUrlBuilder;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tag\TagService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/tags/images` -- `pwg.tags.getImages`'s real replacement.
 * Public, permission-filtered. A collection-level query (matching one or
 * more tags via `tagIds`/`tagUrlNames`/`tagNames`), not a single-tag
 * sub-resource.
 */
final readonly class TagImagesController implements ControllerInterface
{
    public function __construct(
        private TagService $tagService,
        private UrlServiceInterface $urlService,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
        private ImageFilterCriteriaBuilder $imageFilterCriteriaBuilder,
        private ImageUrlBuilder $imageUrlBuilder,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();

        $tagIds = self::intList($query['tagIds'] ?? null);
        $tagUrlNames = self::stringList($query['tagUrlNames'] ?? null);
        $tagNames = self::stringList($query['tagNames'] ?? null);
        $tagModeAnd = ($query['tagModeAnd'] ?? null) === 'true';
        $perPage = isset($query['perPage']) && is_numeric($query['perPage']) ? (int) $query['perPage'] : 100;
        $page = isset($query['page']) && is_numeric($query['page']) ? (int) $query['page'] : 0;
        $order = is_string($query['order'] ?? null) ? $query['order'] : '';

        $tags = $this->tagService->findTags($tagIds, $tagUrlNames, $tagNames);
        $tagsById = [];
        foreach ($tags as $tag) {
            $tagsById[$tag['id']] = $tag;
        }
        $resolvedTagIds = array_keys($tagsById);

        $filterCriteria = $this->imageFilterCriteriaBuilder->stdImageSqlFilterCriteria(ImageFilterQueryInput::fromQueryParams($query));
        if ($filterCriteria instanceof OperationError) {
            return ResponseFactory::problem('Unprocessable Entity', 422, $filterCriteria->message());
        }

        $orderBy = PhotoSortOrder::fromApiOrderParam($order);
        $imageIds = $this->tagService->getImageIdsForTags(
            array_map(TagId::from(...), $resolvedTagIds),
            $tagModeAnd ? 'AND' : 'OR',
            $filterCriteria,
            $orderBy->isEmpty() ? null : $orderBy,
        );
        $imageIds = array_values(array_map(intval(...), array_filter($imageIds, is_numeric(...))));

        $totalCount = count($imageIds);
        $imageIds = array_slice($imageIds, $perPage * $page, $perPage);

        $imageTagMap = [];
        if ($imageIds !== [] && ! $tagModeAnd) {
            foreach ($this->tagService->getCommaJoinedTagIdsByImageIds($resolvedTagIds, $imageIds) as $rowImageId => $tagIdsCsv) {
                $imageTagMap[$rowImageId] = explode(',', $tagIdsCsv);
            }
        }

        $images = [];
        if ($imageIds !== []) {
            $rankOf = array_flip($imageIds);
            $favoriteIds = $this->urlService->getUserFavorites();

            foreach (TypedRepository::narrow($this->entityManager->getRepository(ImageEntity::class), ImageRepository::class)->findByIds($imageIds) as $rowId => $imageEntity) {
                $row = $imageEntity->toArray();

                $nameEvent = $this->eventDispatcher->dispatch(new RenderElementName(is_string($row['name']) ? $row['name'] : '', 'tagImages'));
                $descriptionEvent = $this->eventDispatcher->dispatch(new RenderElementDescription(is_string($row['comment']) ? $row['comment'] : '', 'tagImages'));

                $imageTagIds = $tagModeAnd ? $resolvedTagIds : ($imageTagMap[$rowId] ?? []);
                $imageTags = [];
                foreach ($imageTagIds as $tagIdRaw) {
                    $tagId = is_numeric($tagIdRaw) ? (int) $tagIdRaw : 0;
                    $tag = $tagsById[$tagId] ?? null;
                    if ($tag === null) {
                        continue;
                    }

                    $imageTags[] = [
                        'id' => $tagId,
                        'url' => $this->urlService->makeIndexUrl([
                            'section' => 'tags',
                            'tags' => [$tag],
                        ]),
                        'pageUrl' => $this->urlService->makePictureUrl([
                            'section' => 'tags',
                            'tags' => [$tag],
                            'image_id' => $row['id'],
                            'image_file' => $row['file'],
                        ]),
                    ];
                }

                $images[] = array_merge(
                    [
                        'rank' => $rankOf[$rowId],
                        'isFavorite' => isset($favoriteIds[$rowId]),
                        'id' => $row['id'],
                        'width' => $row['width'],
                        'height' => $row['height'],
                        'hit' => $row['hit'],
                        'file' => $row['file'],
                        'name' => strip_tags($nameEvent->elementName),
                        'comment' => $descriptionEvent->elementDescription,
                        'dateCreation' => $row['date_creation'],
                        'dateAvailable' => $row['date_available'],
                    ],
                    $this->imageUrlBuilder->stdGetUrls($row, $this->urlService),
                    [
                        'tags' => $imageTags,
                    ]
                );
            }

            usort($images, CategoryService::compareByRank(...));
        }

        return ResponseFactory::json([
            'images' => $images,
            'page' => $page,
            'perPage' => $perPage,
            'totalCount' => $totalCount,
        ]);
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $v) {
            if (is_numeric($v)) {
                $ids[] = (int) $v;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $values = [];
        foreach ($raw as $v) {
            if (is_string($v)) {
                $values[] = $v;
            }
        }

        return $values;
    }
}
