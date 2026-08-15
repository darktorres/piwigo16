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
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Event\Picture\RenderElementName;
use Piwigo\Image\ImageRepository;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\SearchService;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\NamedStruct;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsHelper;

/**
 * `pwg.images.search` -- returns a list of elements corresponding to a query search.
 */
final readonly class SearchHandler implements WsAction
{
    public function __construct(
        private WsHelper $wsHelper,
        private CurrentConfig $currentConfig,
        private SearchService $searchService,
        private UrlServiceInterface $urlService,
        private ImageRepository $imageRepository,
        private EventDispatcher $eventDispatcher,
    ) {}

    /**
     * @param array<mixed> $params
     * @return array{paging: NamedStruct, images: NamedArray}
     */
    #[Override]
    public function __invoke(array $params, Server $server): array
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
        $filterCondition = $this->wsHelper->stdImageSqlFilterCriteria($filterParams, $server)
            ->toSqlCondition('i.');
        $order_by = $this->wsHelper->stdImageSqlOrder($filterParams, 'i.');

        $super_order_by = false;
        if ($order_by !== '') {
            // Communicates the effective order_by to SearchService::
            // getQuickSearchResults()/getRegularSearchResults() etc, which
            // read it back from $this->currentConfig-> for the rest of this request --
            // an in-memory-only override ($this->currentConfig->orderBy = ), not a
            // DB write.
            $this->currentConfig->orderBy = 'ORDER BY ' . $order_by;
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
            $input->query,
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

                $nameEvent2 = $this->eventDispatcher->dispatchChange(new RenderElementName(is_string($image['name']) ? $image['name'] : '', 'search'));
                $image['name'] = strip_tags($nameEvent2->elementName);
                $descriptionEvent2 = $this->eventDispatcher->dispatchChange(new RenderElementDescription(is_string($image['comment']) ? $image['comment'] : '', 'search'));
                $image['comment'] = $descriptionEvent2->elementDescription;

                $image = array_merge($image, $this->wsHelper->stdGetUrls($row, $this->urlService));
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
                $this->wsHelper->stdGetImageXmlAttributes()
            ),
        ];
    }
}
