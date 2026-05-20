<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Config\Config;
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Event\Picture\RenderElementName;
use Piwigo\Image\ImageRepository;
use Piwigo\Search\SearchService;
use Piwigo\Url\UrlService;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsHelper;
use Psr\EventDispatcher\EventDispatcherInterface;

/** `pwg.images.search` — quick search; orders + filters + image fan-out + paging. */
final readonly class SearchHandler implements WsAction
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private ImageRepository $imageRepository,
        private SearchService $searchService,
        private UrlService $urlService,
        private WsHelper $wsHelper,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): array
    {
        $input    = SearchParams::fromArray($params);
        $pQuery   = $input->query;
        $pPage    = $input->page;
        $pPerPage = $input->perPage;
        $images   = [];
        /** @var array<string> $whereClauses */
        $whereClauses = $this->wsHelper->imageSqlFilter($params, 'i.');
        $orderBy      = $this->wsHelper->imageSqlOrder($params, 'i.');
        $superOrderBy = false;
        if (!empty($orderBy)) {
            Config::override('order_by', 'ORDER BY ' . $orderBy);
            $superOrderBy = true;
        }
        $searchResult    = $this->searchService->getQuickSearchResults($pQuery, ['super_order_by' => $superOrderBy, 'images_where' => implode(' AND ', $whereClauses)]);
        $searchResultArr = $searchResult ?? [];
        $searchItems     = is_array($searchResultArr['items'] ?? null) ? $searchResultArr['items'] : [];
        $imageIds        = array_slice($searchItems, $pPage * $pPerPage, $pPerPage);
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
                $renderEvent2 = new RenderElementName($img->name ?? '', $image);
                $this->dispatcher->dispatch($renderEvent2);
                $image['name'] = strip_tags($renderEvent2->elementName);
                $imgDescEvent  = new RenderElementDescription($img->comment ?? '', __FUNCTION__);
                $this->dispatcher->dispatch($imgDescEvent);
                $image['comment'] = $imgDescEvent->elementDescription;
                $image            = array_merge($image, $this->wsHelper->getUrls([
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
}
