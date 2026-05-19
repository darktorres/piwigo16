<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Image\ImageRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.images.setRank` — reorder image(s) within a category. */
final readonly class SetRankHandler implements WsAction
{
    public function __construct(
        private CategoryAdminService $categoryAdminService,
        private CategoryRepository $categoryRepository,
        private ImageRepository $imageRepository,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): array|PwgError
    {
        $pImageIdArr   = is_array($params['image_id']) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['image_id']) : [];
        $pCategoryId   = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        if (count($pImageIdArr) > 1) {
            $this->categoryAdminService->saveImagesOrder($pCategoryId, $pImageIdArr);
            $imageIds = $this->imageRepository->findIdsByCategoryIdOrderedByRank($pCategoryId);
            return ['image_id' => $imageIds, 'category_id' => $pCategoryId];
        }
        $pImageId = $pImageIdArr[0] ?? 0;
        if (empty($params['rank'])) {
            return new PwgError(WsError::MissingParam->value, 'rank is missing');
        }
        $catRepo = $this->categoryRepository;
        if (!$this->imageRepository->existsById($pImageId)) {
            return new PwgError(404, 'image_id not found');
        }
        if (!$catRepo->hasImageInCategory($pImageId, $pCategoryId)) {
            return new PwgError(404, 'This image is not associated to this category');
        }
        $pRank   = is_numeric($params['rank']) ? (int) $params['rank'] : 1;
        $maxRank = $catRepo->findMaxRankInCategory($pCategoryId);
        if ($maxRank !== null) {
            if ($pRank > $maxRank) {
                $pRank = $maxRank + 1;
            }
        } else {
            $pRank = 1;
        }
        $catRepo->incrementRanksFrom($pCategoryId, $pRank);
        $catRepo->setImageRank($pImageId, $pCategoryId, $pRank);
        return ['image_id' => $pImageId, 'category_id' => $pCategoryId, 'rank' => $pRank];
    }
}
