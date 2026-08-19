<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

use Override;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImagePathHelper;
use Piwigo\Image\ImageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/images/formats/actions/delete` --
 * `pwg.images.formats.delete`'s real replacement, admin + CSRF. Removes
 * a format from the database and the filesystem.
 */
final readonly class ImageFormatDeleteController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private ImageService $imageService,
        private UrlServiceInterface $urlService,
        private Paths $paths,
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

        $input = ImageFormatDeleteInput::fromArray(JsonBody::decode($request));

        $imageIds = [];
        $formatsOf = [];
        foreach ($this->imageService->getImageIdsAndExtsByFormatIds($input->formatIds) as $row) {
            if (! isset($formatsOf[$row->imageId])) {
                $imageIds[] = $row->imageId;
                $formatsOf[$row->imageId] = [];
            }

            $formatsOf[$row->imageId][] = $row->ext;
        }

        if ($imageIds === []) {
            return ResponseFactory::problem('Not Found', 404, 'No format found for the id(s) given.');
        }

        $ok = true;
        foreach ($this->imageService->getPathsForFileDeletion($imageIds) as $imageRow) {
            if ($this->urlService->urlIsRemote($imageRow->path)) {
                continue;
            }

            $imagePath = ImagePathHelper::getElementPath($imageRow->path, $this->urlService, $this->paths);

            $files = [];
            foreach ($formatsOf[$imageRow->id] ?? [] as $formatExt) {
                $files[] = ImagePathHelper::originalToFormat($imagePath, $formatExt);
            }

            foreach ($files as $path) {
                if (is_file($path) && ! unlink($path)) {
                    $ok = false;
                    trigger_error('"' . $path . '" cannot be removed', E_USER_WARNING);
                    break;
                }
            }
        }

        $this->imageService->deleteFormatsByIds($input->formatIds);
        PermissionCacheInvalidator::invalidate();

        if (! $ok) {
            return ResponseFactory::problem('Internal Server Error', 500, 'One or more format files could not be removed.');
        }

        return ResponseFactory::noContent();
    }
}
