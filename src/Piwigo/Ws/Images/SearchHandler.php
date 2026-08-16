<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Override;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Html\Event\RenderElementDescription;
use Piwigo\Html\Event\RenderElementName;
use Piwigo\Image\ImageRepository;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\SearchService;
use Piwigo\Sort\OrderBy;
use Piwigo\Ws\ImageFilterCriteriaBuilder;
use Piwigo\Ws\ImageUrlBuilder;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\NamedStruct;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\XmlAttributeLists;

/**
 * `pwg.images.search` -- returns a list of elements corresponding to a query search.
 */
final readonly class SearchHandler implements WsAction
{
    public function __construct(
        private ImageFilterCriteriaBuilder $imageFilterCriteriaBuilder,
        private ImageUrlBuilder $imageUrlBuilder,
        private XmlAttributeLists $xmlAttributeLists,
        private SearchService $searchService,
        private UrlServiceInterface $urlService,
        private ImageRepository $imageRepository,
        private EventDispatcher $eventDispatcher,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{paging: NamedStruct, images: NamedArray}
     */
    #[Override]
    public function __invoke(array $params): WsErrorResponse|array
    {
        $input = SearchParams::fromArray($params);

        // MethodDefinition's own registration for this method merges
        // ws.php's shared $f_params plus 'order' into its param list, so
        // Server::invoke()'s generic validation guarantees this exact
        // shape before __invoke() ever runs -- WsAction::__invoke()'s own
        // $params type can't express that (every handler shares the same
        // loose array<mixed> contract), so it's asserted locally at this
        // one call site instead.
        /** @var array{f_min_rate: float|null, f_max_rate: float|null, f_min_hit: int|null, f_max_hit: int|null, f_min_ratio: float|null, f_max_ratio: float|null, f_max_level: int|null, f_min_date_available: string|null, f_max_date_available: string|null, f_min_date_created: string|null, f_max_date_created: string|null, order: string|null, ...} */
        $filterParams = $params;

        $images = [];
        $filterCriteria = $this->imageFilterCriteriaBuilder->stdImageSqlFilterCriteria($filterParams);
        if ($filterCriteria instanceof WsErrorResponse) {
            return $filterCriteria;
        }
        $filterCondition = $filterCriteria->toSqlCondition('i.');
        $order = $filterParams['order'];
        $orderBy = OrderBy::fromWsOrderParam($order ?? '');

        $super_order_by = false;
        $orderByOverride = null;
        if (! $orderBy->isEmpty()) {
            // Passed straight to SearchService::getQuickSearchResults()
            // as an explicit argument now, rather than mutated onto the
            // shared CurrentConfig instance -- that would otherwise leak
            // into every other consumer for the rest of this request (and
            // across requests under worker mode).
            $orderByOverride = $orderBy;
            $super_order_by = true; // quick_search_result might be faster
        }

        $search_result = $this->searchService->getQuickSearchResults(
            $input->query,
            [
                'super_order_by' => $super_order_by,
                'images_where' => $filterCondition,
            ],
            $orderByOverride
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
                $input->page * $input->perPage,
                $input->perPage
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
                // ImageUrlBuilder::stdGetUrls(array $image_row, ...), both of
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

                $nameEvent2 = $this->eventDispatcher->dispatch(new RenderElementName(is_string($image['name']) ? $image['name'] : '', 'search'));
                $image['name'] = strip_tags($nameEvent2->elementName);
                $descriptionEvent2 = $this->eventDispatcher->dispatch(new RenderElementDescription(is_string($image['comment']) ? $image['comment'] : '', 'search'));
                $image['comment'] = $descriptionEvent2->elementDescription;

                $image = array_merge($image, $this->imageUrlBuilder->stdGetUrls($row, $this->urlService));
                $images[$image_ids[$image['id']]] = $image;
            }
            ksort($images, SORT_NUMERIC);
            $images = array_values($images);
        }

        return [
            'paging' => new NamedStruct(
                [
                    'page' => $input->page,
                    'per_page' => $input->perPage,
                    'count' => count($images),
                    'total_count' => count($search_items),
                ]
            ),
            'images' => new NamedArray(
                $images,
                'image',
                $this->xmlAttributeLists->stdGetImageXmlAttributes()
            ),
        ];
    }
}
