<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Tags;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Event\Picture\RenderElementName;
use Piwigo\Image\ImageEntity;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tag\TagService;
use Piwigo\Ws\ImageFilterCriteriaBuilder;
use Piwigo\Ws\ImageSqlOrderBuilder;
use Piwigo\Ws\ImageUrlBuilder;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\NamedStruct;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\XmlAttributeLists;

/**
 * `pwg.tags.getImages` -- returns elements for the corresponding tags.
 */
final readonly class GetImagesHandler implements WsAction
{
    public function __construct(
        private TagService $tagService,
        private UrlServiceInterface $urlService,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
        private ImageFilterCriteriaBuilder $imageFilterCriteriaBuilder,
        private ImageSqlOrderBuilder $imageSqlOrderBuilder,
        private ImageUrlBuilder $imageUrlBuilder,
        private XmlAttributeLists $xmlAttributeLists,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{paging: NamedStruct, images: NamedArray}
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = GetImagesParams::fromArray($params);
        $tagService = $this->tagService;

        // first build all the tag_ids we are interested in
        $tags = $tagService->findTags($input->tagIds, $input->tagUrlNames, $input->tagNames);
        $tags_by_id = [];
        foreach ($tags as $tag) {
            $tags_by_id[$tag['id']] = $tag;
        }
        unset($tags);
        $tag_ids = array_keys($tags_by_id);

        // MethodDefinition's own registration for this method merges
        // SharedImageFilterParams::get() plus 'order' into
        // its param list, so Server::invoke()'s generic validation
        // guarantees this exact shape before __invoke() ever runs --
        // WsAction::__invoke()'s own $params type can't express that
        // (every handler shares the same loose array<mixed> contract),
        // so it's asserted locally at this one call site instead.
        /** @var array{f_min_rate: float|null, f_max_rate: float|null, f_min_hit: int|null, f_max_hit: int|null, f_min_ratio: float|null, f_max_ratio: float|null, f_max_level: int|null, f_min_date_available: string|null, f_max_date_available: string|null, f_min_date_created: string|null, f_max_date_created: string|null, order: string|null, ...} */
        $filterParams = $params;

        $filterCriteria = $this->imageFilterCriteriaBuilder->stdImageSqlFilterCriteria($filterParams);
        if ($filterCriteria instanceof WsErrorResponse) {
            return $filterCriteria;
        }

        $order_by = $this->imageSqlOrderBuilder->stdImageSqlOrder($filterParams, 'i.');
        if ($order_by !== '') {
            $order_by = 'ORDER BY ' . $order_by;
        }
        $image_ids = $tagService->getImageIdsForTags(
            array_map(TagId::from(...), $tag_ids),
            $input->tagModeAnd ? 'AND' : 'OR',
            $filterCriteria,
            $order_by,
        );
        // Cast to int at the source (not just at each read site) so
        // array_flip($image_ids) below produces int keys matching $row_id's
        // (int) cast, instead of leaving PHPStan-only string keys.
        $image_ids = array_values(array_map(intval(...), array_filter($image_ids, is_numeric(...))));

        $count_set = count($image_ids);
        $image_ids = array_slice($image_ids, $input->perPage * $input->page, $input->perPage);

        $image_tag_map = [];
        // build list of image ids with associated tags per image
        if ($image_ids !== [] and ! $input->tagModeAnd) {
            foreach ($tagService->getCommaJoinedTagIdsByImageIds($tag_ids, $image_ids) as $row_image_id => $tag_ids_csv) {
                $image_tag_map[$row_image_id] = explode(',', $tag_ids_csv);
            }
        }

        $images = [];
        $urlService = $this->urlService;
        if ($image_ids !== []) {
            $rank_of = array_flip($image_ids);
            $favorite_ids = $urlService->getUserFavorites();

            foreach ($this->entityManager->getRepository(ImageEntity::class)->findByIds($image_ids) as $row_id => $imageRow) {
                // Unboxed here rather than kept as the typed object -- this
                // loop rebuilds a differently-shaped $image array from
                // $row's fields and separately passes the whole row to
                // ImageUrlBuilder::stdGetUrls(array $image_row, ...), both of
                // which need real array semantics.
                $row = $imageRow->toArray();

                $image = [];
                $image['rank'] = $rank_of[$row_id];
                $image['is_favorite'] = isset($favorite_ids[$row_id]);

                foreach (['id', 'width', 'height', 'hit'] as $k) {
                    if (isset($row[$k])) {
                        $image[$k] = $row[$k];
                    }
                }
                foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                    $image[$k] = $row[$k];
                }

                $nameEvent = $this->eventDispatcher->dispatchChange(new RenderElementName(is_string($image['name']) ? $image['name'] : '', __FUNCTION__));
                $image['name'] = strip_tags($nameEvent->elementName);
                $descriptionEvent = $this->eventDispatcher->dispatchChange(new RenderElementDescription(is_string($image['comment']) ? $image['comment'] : '', __FUNCTION__));
                $image['comment'] = $descriptionEvent->elementDescription;

                $image = array_merge($image, $this->imageUrlBuilder->stdGetUrls($row, $urlService));

                $image_tag_ids = $input->tagModeAnd ? $tag_ids : $image_tag_map[$row_id];
                $image_tags = [];
                foreach ($image_tag_ids as $tag_id_raw) {
                    $tag_id = is_numeric($tag_id_raw) ? (int) $tag_id_raw : 0;
                    $url = $urlService->makeIndexUrl(
                        [
                            'section' => 'tags',
                            'tags' => [$tags_by_id[$tag_id]],
                        ]
                    );
                    $page_url = $urlService->makePictureUrl(
                        [
                            'section' => 'tags',
                            'tags' => [$tags_by_id[$tag_id]],
                            'image_id' => $row['id'],
                            'image_file' => $row['file'],
                        ]
                    );
                    $image_tags[] = [
                        'id' => $tag_id,
                        'url' => $url,
                        'page_url' => $page_url,
                    ];
                }

                $image['tags'] = new NamedArray($image_tags, 'tag', $this->xmlAttributeLists->stdGetTagXmlAttributes());
                $images[] = $image;
            }

            usort($images, CategoryService::compareByRank(...));
            unset($rank_of);
        }

        return [
            'paging' => new NamedStruct(
                [
                    'page' => $input->page,
                    'per_page' => $input->perPage,
                    'count' => count($images),
                    'total_count' => $count_set,
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
