<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Uploads;

use Override;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Html\HtmlService;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/uploads/actions/complete-batch` --
 * `pwg.images.uploadCompleted`'s real replacement, admin + CSRF.
 * Notifies the server that a
 * batch of tus uploads is finished; empties the lounge, if any.
 */
final readonly class UploadCompleteBatchController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private ImageService $imageService,
        private HtmlService $htmlService,
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

        $input = UploadCompleteBatchInput::fromArray(JsonBody::decode($request));

        $movedFromLounge = $this->imageService->emptyLounge();

        $nbPhotos = $this->imageService->countImagesInCategory(CategoryId::from($input->categoryId));
        $categoryName = $this->htmlService->getCatDisplayNameFromId($input->categoryId, null);

        return ResponseFactory::json([
            'movedFromLounge' => $movedFromLounge,
            'category' => [
                'id' => $input->categoryId,
                'nbPhotos' => $nbPhotos,
                'label' => $categoryName,
            ],
        ]);
    }
}
