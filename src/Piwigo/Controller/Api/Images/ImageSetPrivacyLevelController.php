<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Config\CurrentConfig;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/images/actions/set-privacy-level` --
 * `pwg.images.setPrivacyLevel`'s real replacement, admin + CSRF
 * (`requiresAuth: true` on the WS side really means admin_only).
 * `Ws\Images\SetPrivacyLevelHandler` itself has no CSRF check (a real,
 * standing gap in its own registered params) -- this fresh
 * implementation adds one anyway, matching every other mutating
 * `/api/v1` endpoint.
 */
final readonly class ImageSetPrivacyLevelController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private ImageService $imageService,
        private ActivityService $activityService,
        private CurrentConfig $currentConfig,
        private EntityManagerInterface $entityManager,
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

        $input = ImageSetPrivacyLevelInput::fromArray(JsonBody::decode($request));

        if (! in_array($input->level, $this->currentConfig->availablePermissionLevels, true)) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid level.');
        }

        $affectedRows = $this->imageService->updateLevelForImages($input->imageIds, $input->level);
        $this->entityManager->clear();

        $this->activityService->record('photo', $input->imageIds, 'edit');

        if ($affectedRows > 0) {
            PermissionCacheInvalidator::invalidate();
        }

        return ResponseFactory::json([
            'affectedRows' => $affectedRows,
        ]);
    }
}
