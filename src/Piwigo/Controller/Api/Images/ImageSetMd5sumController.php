<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

use Override;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/images/actions/set-md5sum` -- `pwg.images.setMd5sum`'s
 * real replacement, admin + CSRF. Adds md5sums to photos missing one, by
 * block -- the real caller polls this repeatedly, one block per call,
 * until `remainingCount` reaches 0.
 */
final readonly class ImageSetMd5sumController implements ControllerInterface
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

        $input = ImageSetMd5sumInput::fromArray(JsonBody::decode($request));

        $noMd5sumIds = $this->imageService->getPhotosNoMd5sum();
        $addedCount = 0;

        if ($noMd5sumIds !== []) {
            $idsToAdd = array_slice($noMd5sumIds, 0, $input->blockSize);
            $addedCount = $this->imageService->addMd5sum($idsToAdd);
        }

        return ResponseFactory::json([
            'addedCount' => $addedCount,
            'remainingCount' => count($this->imageService->getPhotosNoMd5sum()),
        ]);
    }
}
