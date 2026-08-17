<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

use Override;
use Piwigo\Common\ValueObject\PhotoSortOrder;
use Piwigo\Controller\Api\ImageFilterQueryInput;
use Piwigo\Core\OperationError;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Html\Event\RenderElementDescription;
use Piwigo\Html\Event\RenderElementName;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageFilterCriteriaBuilder;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageUrlBuilder;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\SearchService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/images/search` -- `pwg.images.search`'s real replacement.
 * Public, permission-filtered (no `AdminGuard`).
 */
final readonly class ImageSearchController implements ControllerInterface
{
    public function __construct(
        private ImageFilterCriteriaBuilder $imageFilterCriteriaBuilder,
        private ImageUrlBuilder $imageUrlBuilder,
        private SearchService $searchService,
        private UrlServiceInterface $urlService,
        private ImageRepository $imageRepository,
        private EventDispatcher $eventDispatcher,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();

        $q = is_string($query['query'] ?? null) ? $query['query'] : '';
        $perPage = isset($query['perPage']) && is_numeric($query['perPage']) ? (int) $query['perPage'] : 100;
        $page = isset($query['page']) && is_numeric($query['page']) ? (int) $query['page'] : 0;
        $order = is_string($query['order'] ?? null) ? $query['order'] : '';

        $images = [];
        $filterCriteria = $this->imageFilterCriteriaBuilder->stdImageSqlFilterCriteria(ImageFilterQueryInput::fromQueryParams($query));
        if ($filterCriteria instanceof OperationError) {
            return ResponseFactory::problem('Unprocessable Entity', 422, $filterCriteria->message());
        }
        $filterCondition = $filterCriteria->toSqlCondition('i.');

        $orderBy = PhotoSortOrder::fromWsOrderParam($order);
        $superOrderBy = false;
        $orderByOverride = null;
        if (! $orderBy->isEmpty()) {
            $orderByOverride = $orderBy;
            $superOrderBy = true;
        }

        $searchResult = $this->searchService->getQuickSearchResults(
            $q,
            [
                'super_order_by' => $superOrderBy,
                'images_where' => $filterCondition,
            ],
            $orderByOverride
        );

        $searchItems = $searchResult['items'];
        if (! is_array($searchItems)) {
            $searchItems = [];
        }

        $imageIds = array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_slice($searchItems, $page * $perPage, $perPage)
        );

        if ($imageIds !== []) {
            $imageIdsFlipped = array_flip($imageIds);
            $favoriteIds = $this->urlService->getUserFavorites();

            foreach ($this->imageRepository->findByIds(array_keys($imageIdsFlipped)) as $imageRow) {
                $row = $imageRow->toArray();

                $nameEvent = $this->eventDispatcher->dispatch(new RenderElementName(is_string($row['name']) ? $row['name'] : '', 'search'));
                $descriptionEvent = $this->eventDispatcher->dispatch(new RenderElementDescription(is_string($row['comment']) ? $row['comment'] : '', 'search'));

                $image = array_merge(
                    [
                        'isFavorite' => isset($favoriteIds[$imageRow->id->value]),
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
                    $this->imageUrlBuilder->stdGetUrls($row, $this->urlService)
                );

                $images[$imageIdsFlipped[$image['id']]] = $image;
            }
            ksort($images, SORT_NUMERIC);
            $images = array_values($images);
        }

        return ResponseFactory::json([
            'images' => $images,
            'page' => $page,
            'perPage' => $perPage,
            'totalCount' => count($searchItems),
        ]);
    }
}
