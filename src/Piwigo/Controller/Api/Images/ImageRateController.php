<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionService;
use Piwigo\Rate\RateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PUT /api/v1/images/{id}/rating` -- `pwg.images.rate`'s real
 * replacement. Public, permission-filtered (no `AdminGuard`).
 */
final readonly class ImageRateController implements ControllerInterface
{
    public function __construct(
        private ImageService $imageService,
        private PermissionService $permissionService,
        private RateService $rateService,
        private CurrentConfig $currentConfig,
        private EntityManagerInterface $entityManager,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $routeArgs = $request->getAttribute('route_args');
        $rawId = is_array($routeArgs) ? ($routeArgs['id'] ?? null) : null;
        $imageId = is_string($rawId) ? (int) $rawId : 0;

        if (! $this->imageService->isImageAccessibleWithCondition(ImageId::from($imageId), $this->permissionService->getPermissionCriteria())) {
            return ResponseFactory::problem('Not Found', 404, 'Invalid image id or access denied.');
        }

        $input = ImageRateInput::fromArray(JsonBody::decode($request));

        $result = $this->rateService->rate($imageId, $input->rate, $this->entityManager);
        if ($result === false) {
            return ResponseFactory::problem(
                'Forbidden',
                403,
                'Forbidden or rate not in ' . implode(',', $this->currentConfig->rateItems) . '.'
            );
        }

        return ResponseFactory::json([
            'score' => $result['score'],
            'average' => $result['average'],
            'count' => $result['count'],
        ]);
    }
}
