<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

use Override;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/images/actions/set-rank` -- `pwg.images.setRank`'s real
 * replacement, admin + CSRF (`requiresAuth: true` on the WS side really
 * means admin_only). `Ws\Images\SetRankHandler` itself has no CSRF
 * check -- this fresh implementation adds one anyway, matching every
 * other mutating `/api/v1` endpoint.
 */
final readonly class ImageSetRankController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private ImageService $imageService,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $csrfDenied = $this->csrfGuard->check($request);
        if ($csrfDenied instanceof ResponseInterface) {
            return $csrfDenied;
        }

        $input = ImageSetRankInput::fromArray(JsonBody::decode($request));

        if (count($input->imageIds) > 1) {
            $this->imageService->saveImagesOrder($input->categoryId, $input->imageIds);
            $orderedImageIds = $this->imageService->getImageIdsOrderedByRankForCategory(CategoryId::from($input->categoryId));

            return ResponseFactory::json([
                'imageIds' => $orderedImageIds,
                'categoryId' => $input->categoryId,
            ]);
        }

        $imageIdValue = $input->imageIds[0] ?? null;
        if ($imageIdValue === null) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'imageIds is missing.');
        }

        if ($input->rank === null || $input->rank === 0) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'rank is missing.');
        }
        $rank = $input->rank;

        $imageId = ImageId::from($imageIdValue);
        $categoryId = CategoryId::from($input->categoryId);

        if (! $this->imageService->existsById($imageId)) {
            return ResponseFactory::problem('Not Found', 404, 'image_id not found.');
        }

        if (! $this->imageService->isImageInCategory($imageId, $categoryId)) {
            return ResponseFactory::problem('Not Found', 404, 'This image is not associated to this album.');
        }

        $maxRank = $this->imageService->getMaxRankForCategory($categoryId);
        if ($maxRank !== null) {
            if ($rank > $maxRank) {
                $rank = $maxRank + 1;
            }
        } else {
            $rank = 1;
        }

        $this->imageService->incrementRanksFromForCategory($categoryId, $rank);
        $this->imageService->updateRankForImageInCategory($imageId, $categoryId, $rank);

        return ResponseFactory::json([
            'imageId' => $imageIdValue,
            'categoryId' => $input->categoryId,
            'rank' => $rank,
        ]);
    }
}
