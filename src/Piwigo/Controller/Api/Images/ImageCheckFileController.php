<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

use Override;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImagePathHelper;
use Piwigo\Image\ImageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/images/{id}/actions/check-file?highSum=...&fileSum=...` --
 * `pwg.images.checkFiles`'s real replacement, admin only. `thumbnail_sum`
 * is dropped -- backward-compat cruft (its value was never read, only
 * its presence, and the response for it was unconditionally `'equals'`).
 */
final readonly class ImageCheckFileController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
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

        $routeArgs = $request->getAttribute('route_args');
        $rawId = is_array($routeArgs) ? ($routeArgs['id'] ?? null) : null;
        $imageId = is_string($rawId) ? (int) $rawId : 0;

        $path = $this->imageService->getPathById(ImageId::from($imageId));
        if ($path === null) {
            return ResponseFactory::problem('Not Found', 404, 'image_id not found.');
        }

        $path = ImagePathHelper::getElementPath([
            'path' => $path,
        ], $this->urlService, $this->paths);

        $query = $request->getQueryParams();
        $highSum = $query['highSum'] ?? null;
        $fileSum = $query['fileSum'] ?? null;

        $compareType = null;
        $compareSum = null;
        $result = [];
        if (is_string($highSum) && $highSum !== '') {
            $result['file'] = 'equals';
            $compareType = 'high';
            $compareSum = $highSum;
        } elseif (is_string($fileSum) && $fileSum !== '') {
            $compareType = 'file';
            $compareSum = $fileSum;
        }

        if ($compareType !== null) {
            $result[$compareType] = md5_file($path) === $compareSum ? 'equals' : 'differs';
        }

        return ResponseFactory::json($result);
    }
}
