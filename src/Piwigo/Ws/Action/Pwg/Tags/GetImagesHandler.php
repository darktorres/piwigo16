<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

use Piwigo\Category\CategoryService;
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Event\Picture\RenderElementName;
use Piwigo\Image\ImageRepository;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Url\UrlService;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsHelper;
use Psr\EventDispatcher\EventDispatcherInterface;

/** `pwg.tags.getImages` — paginated image list scoped to a tag set. */
final readonly class GetImagesHandler implements WsAction
{
    public function __construct(
        private CategoryService $categoryService,
        private EventDispatcherInterface $dispatcher,
        private ImageRepository $imageRepository,
        private TagRepository $tagRepository,
        private TagService $tagService,
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
        $input = GetImagesParams::fromArray($params);
        /** @var array<int, array<string, mixed>> $tagsResult */
        $tagsResult = $this->tagService->findTags($input->tagIds, $input->tagUrlNames, $input->tagNames);
        $tagsById   = [];
        foreach ($tagsResult as $tag) {
            $tagIdVal            = is_numeric($tag['id'] ?? null) ? (int) $tag['id'] : 0;
            $tagsById[$tagIdVal] = $tag;
        }
        $tagIds = array_keys($tagsById);
        /** @var string[] $whereClausesArr */
        $whereClausesArr = $this->wsHelper->imageSqlFilter($params);
        $whereClauses    = !empty($whereClausesArr) ? implode(' AND ', $whereClausesArr) : null;
        $orderBy         = $this->wsHelper->imageSqlOrder($params, 'i.');
        if (!empty($orderBy)) {
            $orderBy = 'ORDER BY ' . $orderBy;
        }
        $imageIds    = $this->tagService->getImageIdsForTags($tagIds, $input->tagModeAnd ? 'AND' : 'OR', $whereClauses, $orderBy);
        $countSet    = count($imageIds);
        $perPage     = $input->perPage;
        $page        = $input->page;
        $imageIds    = array_slice($imageIds, $perPage * $page, $perPage);
        $imageTagMap = [];
        if (!empty($imageIds) && !$input->tagModeAnd) {
            foreach ($this->tagRepository->findImageTagMap($tagIds, $imageIds) as $group) {
                $imageTagMap[$group->imageId->value] = explode(',', $group->tagIdsCsv);
            }
        }
        $images = [];
        if (!empty($imageIds)) {
            $rankOf      = array_flip($imageIds);
            $favoriteIds = $this->urlService->getUserFavorites();
            foreach ($this->imageRepository->findByIds($imageIds) as $img) {
                $imgId   = $img->id->value;
                $imgFile = $img->file->value;
                $image   = [
                    'rank'           => $rankOf[$imgId] ?? 0,
                    'is_favorite'    => isset($favoriteIds[$imgId]),
                    'id'             => $imgId,
                    'width'          => $img->width ?? 0,
                    'height'         => $img->height ?? 0,
                    'hit'            => $img->hit,
                    'file'           => $imgFile,
                    'name'           => $img->name,
                    'comment'        => $img->comment,
                    'date_creation'  => $img->dateCreation?->value,
                    'date_available' => $img->dateAvailable?->value,
                ];
                $renderEvent = new RenderElementName($img->name ?? '', $image);
                $this->dispatcher->dispatch($renderEvent);
                $image['name'] = strip_tags($renderEvent->elementName);
                $imgDescEvent  = new RenderElementDescription($img->comment ?? '', __FUNCTION__);
                $this->dispatcher->dispatch($imgDescEvent);
                $image['comment'] = $imgDescEvent->elementDescription;
                $image = array_merge($image, $this->wsHelper->getUrls([
                    'id'                 => $imgId,
                    'file'               => $imgFile,
                    'path'               => $img->path->value,
                    'representative_ext' => $img->representativeExt,
                    'width'              => $img->width,
                    'height'             => $img->height,
                    'rotation'           => $img->rotation ?? 0,
                ]));
                $imageTagIds = $input->tagModeAnd ? $tagIds : ($imageTagMap[$imgId] ?? []);
                $imageTags   = [];
                foreach ($imageTagIds as $tagId) {
                    $tagIdInt = (int) $tagId;
                    if (!isset($tagsById[$tagIdInt])) {
                        continue;
                    }
                    $url         = $this->urlService->makeIndexUrl(['section' => 'tags', 'tags' => [$tagsById[$tagIdInt]]]);
                    $pageUrl     = $this->urlService->makePictureUrl(['section' => 'tags', 'tags' => [$tagsById[$tagIdInt]], 'image_id' => $imgId, 'image_file' => $imgFile]);
                    $imageTags[] = ['id' => $tagIdInt, 'url' => $url, 'page_url' => $pageUrl];
                }
                $image['tags'] = new PwgNamedArray($imageTags, 'tag', $this->wsHelper->getTagXmlAttributes());
                $images[]      = $image;
            }
            usort($images, $this->categoryService->rankCompare(...));
        }
        return ['paging' => new PwgNamedStruct(['page' => $input->page, 'per_page' => $input->perPage, 'count' => count($images), 'total_count' => $countSet]), 'images' => new PwgNamedArray($images, 'image', $this->wsHelper->getImageXmlAttributes())];
    }
}
