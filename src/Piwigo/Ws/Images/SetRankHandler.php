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
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Image\ImageService;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.images.setRank` -- admin only. Sets the rank of an image in a category.
 */
final readonly class SetRankHandler implements WsAction
{
    public function __construct(
        private ImageService $imageService,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{image_id: list<int|string>, category_id: int}|array{image_id: int, category_id: int, rank: int}
     *   the 2 real return sites have genuinely different shapes -- the
     *   multi-image branch below returns the reordered id list (no rank),
     *   the single-image branch below returns the one image_id plus its
     *   new rank
     */
    #[Override]
    public function __invoke(array $params): array|WsErrorResponse
    {
        $input = SetRankParams::fromArray($params);

        if (count($input->imageIds) > 1) {
            $this->imageService
                ->saveImagesOrder(
                    $input->categoryId,
                    $input->imageIds
                );

            $image_ids = $this->imageService->getImageIdsOrderedByRankForCategory(CategoryId::from($input->categoryId));

            // return data for client
            return [
                'image_id' => $image_ids,
                'category_id' => $input->categoryId,
            ];
        }

        // turns image_id into a simple int instead of array
        $image_id = $input->imageIds[0] ?? null;

        if ($image_id === null) {
            return new WsErrorResponse(WsError::MissingParam->value, 'image_id is missing');
        }

        $rank = $input->rank;
        if ($rank === null || $rank === 0) {
            return new WsErrorResponse(WsError::MissingParam->value, 'rank is missing');
        }

        $imageId = ImageId::from($image_id);
        $categoryId = CategoryId::from($input->categoryId);

        // does the image really exist?
        if (! $this->imageService->existsById($imageId)) {
            return new WsErrorResponse(404, 'image_id not found');
        }

        // is the image associated to this category?
        if (! $this->imageService->isImageInCategory($imageId, $categoryId)) {
            return new WsErrorResponse(404, 'This image is not associated to this category');
        }

        // what is the current higher rank for this category?
        $max_rank = $this->imageService->getMaxRankForCategory($categoryId);

        if ($max_rank !== null) {
            if ($rank > $max_rank) {
                $rank = $max_rank + 1;
            }
        } else {
            $rank = 1;
        }

        // update rank for all other photos in the same category
        $this->imageService->incrementRanksFromForCategory($categoryId, $rank);

        // set the new rank for the photo
        $this->imageService->updateRankForImageInCategory($imageId, $categoryId, $rank);

        // return data for client
        return [
            'image_id' => $image_id,
            'category_id' => $input->categoryId,
            'rank' => $rank,
        ];
    }
}
